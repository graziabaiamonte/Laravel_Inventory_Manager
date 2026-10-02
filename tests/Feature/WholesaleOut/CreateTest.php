<?php

namespace Tests\Feature\WholesaleOut;

use App\Models\Stock;
use App\Models\WholesaleOut;

class CreateTest extends WholesaleOutTestCase
{
    public function test_create_draft_wholesale_out(): void
    {
        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $initialStockArea1 = $stockArea1->quantity;

        // Create WholesaleOut as draft (status = 0)
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'DRAFT-001',
            'description' => 'Test draft wholesale out',
            'status' => 0, // Draft
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 20,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 200.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 20,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert WholesaleOut was created
        $wholesaleOut = WholesaleOut::latest()->first();
        $this->assertNotNull($wholesaleOut);
        $this->assertEquals(0, $wholesaleOut->status, 'WholesaleOut should be draft (status = 0)');
        $this->assertEquals($this->customer->id, $wholesaleOut->customer_id);

        // Assert stock was NOT decremented (draft mode)
        $finalStockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals(
            $initialStockArea1,
            $finalStockArea1,
            'Stock should NOT be decremented for draft WholesaleOut'
        );

        // Assert no backorders were created
        $this->assertEquals(0, $wholesaleOut->backorders()->count(), 'No backorders should exist for draft');
    }

    public function test_create_and_immediately_activate(): void
    {
        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $initialStockArea1 = $stockArea1->quantity;

        // Create WholesaleOut and activate immediately (status = 1)
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'ACTIVE-001',
            'description' => 'Test immediate activation',
            'status' => 1, // Active
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 30,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 300.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 30,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert WholesaleOut was created and activated
        $wholesaleOut = WholesaleOut::latest()->first();
        $this->assertNotNull($wholesaleOut);
        $this->assertEquals(1, $wholesaleOut->status, 'WholesaleOut should be active (status = 1)');

        // Assert stock WAS decremented (active mode)
        $finalStockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals(
            $initialStockArea1 - 30,
            $finalStockArea1,
            'Stock should be decremented when WholesaleOut is activated'
        );

        // Assert no backorders (sufficient stock)
        $this->assertEquals(0, $wholesaleOut->backorders()->count(), 'No backorders needed with sufficient stock');
    }

    public function test_create_with_insufficient_stock_creates_backorder(): void
    {
        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $initialStockArea1 = $stockArea1->quantity;
        $requestedQuantity = $initialStockArea1 + 50; // Request more than available

        // Create WholesaleOut with insufficient stock
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'BACKORDER-001',
            'description' => 'Test backorder on creation',
            'status' => 1, // Active
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => $requestedQuantity,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQuantity * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQuantity,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Assert WholesaleOut was created
        $wholesaleOut = WholesaleOut::latest()->first();
        $this->assertNotNull($wholesaleOut);
        $this->assertEquals(1, $wholesaleOut->status);

        // Assert all available stock was allocated
        $finalStockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals(0, $finalStockArea1, 'All available stock should be allocated');

        // Assert backorder was created for the shortfall
        $woRecord = $wholesaleOut->records->first();
        $backorderQuantity = $woRecord->backorderRecords()->sum('quantity');
        $this->assertEquals(
            50,
            $backorderQuantity,
            'Backorder should be created for the shortfall (50 units)'
        );

        // Assert area assignment reflects SHIPPED quantity (not requested)
        // After the fix, area assignment stores what was actually shipped
        // The requested quantity is still available in $woRecord->quantity
        $areaAssignment = $woRecord->wholesaleOutRecordsArea->first();
        $this->assertEquals(
            $initialStockArea1,
            $areaAssignment->quantity,
            'Area assignment should show shipped quantity (what was actually allocated)'
        );
        $this->assertEquals(
            $requestedQuantity,
            $woRecord->quantity,
            'WholesaleOutRecord quantity should still show requested quantity'
        );
    }
}
