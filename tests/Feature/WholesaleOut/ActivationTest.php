<?php

namespace Tests\Feature\WholesaleOut;

use App\Models\Stock;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;

/**
 * Tests for WholesaleOut activation process (draft -> active)
 * Focuses on stock allocation and backorder creation during activation
 * Single-area mode only
 */
class ActivationTest extends WholesaleOutTestCase
{
    /**
     * Test activating a draft with multiple records where some have sufficient stock
     * and others need backorders.
     */
    public function test_activate_draft_with_mixed_stock_availability(): void
    {
        // Record 1: sufficient stock (100 available, request 50)
        // Record 2: insufficient stock (75 available, request 100)
        $stockRecord1Area1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockRecord2Area1 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $initialStockRecord1 = $stockRecord1Area1->quantity;
        $initialStockRecord2 = $stockRecord2Area1->quantity;

        // Create draft WholesaleOut
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0, // Draft
        ]);

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockRecord1Area1->id,
            'quantity' => 50, // Sufficient stock
        ]);

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record2->id,
            'stock_id' => $stockRecord2Area1->id,
            'quantity' => 100, // Insufficient stock (only 75 available)
        ]);

        // Activate the draft
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 1, // Activate
            'records' => [
                [
                    'id' => $wholesaleOut->records[0]->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockRecord1Area1->id,
                    'quantity' => 50,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 500.00,
                    'vat' => 22,
                ],
                [
                    'id' => $wholesaleOut->records[1]->id,
                    'record_id' => $this->record2->id,
                    'stock_id' => $stockRecord2Area1->id,
                    'quantity' => 100,
                    'unit_price' => 12.00,
                    'discount' => 0,
                    'total_price' => 1200.00,
                    'vat' => 22,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $wholesaleOut->refresh();

        // Assert Record 1: stock fully allocated, no backorder
        $finalStockRecord1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStockRecord1 - 50, $finalStockRecord1, 'Record 1 stock fully allocated');

        $record1 = $wholesaleOut->records->where('record_id', $this->record1->id)->first();
        $this->assertEquals(0, $record1->backorderRecords()->count(), 'No backorder for Record 1');

        // Assert Record 2: all available stock allocated (75), backorder for remainder (25)
        $finalStockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals(0, $finalStockRecord2, 'All Record 2 stock allocated');

        $record2 = $wholesaleOut->records->where('record_id', $this->record2->id)->first();
        $backorderQty = $record2->backorderRecords()->sum('quantity');
        $this->assertEquals(25, $backorderQty, 'Backorder created for 25 units shortfall');
    }

    /**
     * Test activating a draft when the area has zero stock.
     */
    public function test_activate_draft_with_zero_stock(): void
    {
        // Set Area 1 stock to zero
        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockArea1->update(['quantity' => 0]);

        // Create draft requesting from Area 1 (zero stock)
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 50,
        ]);

        // Activate
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 1,
            'records' => [
                [
                    'id' => $wholesaleOut->records->first()->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 50,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 500.00,
                    'vat' => 22,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $wholesaleOut->refresh();
        $record = $wholesaleOut->records->first();

        // Assert stock remains zero
        $finalStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals(0, $finalStock, 'Stock remains zero');

        // Assert full backorder created
        $backorderQty = $record->backorderRecords()->sum('quantity');
        $this->assertEquals(50, $backorderQty, 'Full backorder for 50 units');
    }

    /**
     * Test activating when changing the source area during activation.
     */
    public function test_activate_with_area_change(): void
    {
        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockArea2 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first();

        $initialStockArea1 = $stockArea1->quantity;
        $initialStockArea2 = $stockArea2->quantity;

        // Create draft requesting from Area 1
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 30,
        ]);

        // Activate but change WholesaleOut area to Area 2 and use stock from Area 2
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area2->id, // Changed to Area 2
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 1,
            'records' => [
                [
                    'id' => $wholesaleOut->records->first()->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea2->id, // Changed to Area 2
                    'quantity' => 30,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 300.00,
                    'vat' => 22,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert Area 1 stock unchanged
        $finalStockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStockArea1, $finalStockArea1, 'Area 1 stock unchanged');

        // Assert Area 2 stock decremented
        $finalStockArea2 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first()->quantity;
        $this->assertEquals($initialStockArea2 - 30, $finalStockArea2, 'Area 2: 30 allocated');

        // No backorders (sufficient stock in Area 2)
        $wholesaleOut->refresh();
        $record = $wholesaleOut->records->first();
        $this->assertEquals(0, $record->backorderRecords()->count(), 'No backorders');
    }

    /**
     * Test activating when no stock record exists for the area.
     */
    public function test_activate_when_no_stock_record_exists(): void
    {
        // Note: Record2 in Area2 has no stock by default in test setup
        // Verify no stock record exists
        $this->assertNull(
            Stock::where('record_id', $this->record2->id)
                ->where('area_id', $this->area2->id)
                ->first()
        );

        // Create draft requesting from Area 2 (no stock record for record2)
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area2->id,
            'status' => 0,
        ]);

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record2->id,
            'stock_id' => null, // No stock_id because no stock record exists
            'quantity' => 30,
        ]);

        // Activate
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area2->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 1,
            'records' => [
                [
                    'id' => $wholesaleOut->records->first()->id,
                    'record_id' => $this->record2->id,
                    'stock_id' => null,
                    'quantity' => 30,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 300.00,
                    'vat' => 22,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $wholesaleOut->refresh();
        $record = $wholesaleOut->records->first();

        // Assert no stock record created (system doesn't create stock records)
        $this->assertNull(
            Stock::where('record_id', $this->record2->id)
                ->where('area_id', $this->area2->id)
                ->first(),
            'No stock record should be created'
        );

        // Assert full backorder created (no stock available)
        $backorderQty = $record->backorderRecords()->sum('quantity');
        $this->assertEquals(30, $backorderQty, 'Full backorder for 30 units');
    }

    /**
     * Test immediate activation on creation with insufficient stock creates backorder.
     */
    public function test_immediate_activation_on_creation_with_backorder(): void
    {
        $stockRecord1Area1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockRecord2Area1 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $initialStockRecord1 = $stockRecord1Area1->quantity;
        $initialStockRecord2 = $stockRecord2Area1->quantity;

        // Create and immediately activate with:
        // - Record 1: sufficient stock (request 40 of 100 available)
        // - Record 2: insufficient stock (request 100 of 75 available)
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'ACTIVATE-001',
            'description' => 'Immediate activation with backorder',
            'status' => 1, // Immediate activation
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockRecord1Area1->id,
                    'quantity' => 40,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 400.00,
                    'vat' => 22,
                ],
                [
                    'record_id' => $this->record2->id,
                    'stock_id' => $stockRecord2Area1->id,
                    'quantity' => 100, // Request more than available
                    'unit_price' => 12.00,
                    'discount' => 0,
                    'total_price' => 1200.00,
                    'vat' => 22,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $wholesaleOut = WholesaleOut::latest()->first();
        $this->assertNotNull($wholesaleOut);
        $this->assertEquals(1, $wholesaleOut->status, 'Should be active');

        // Assert Record 1: fully allocated, no backorder
        $finalStockRecord1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStockRecord1 - 40, $finalStockRecord1, 'Record 1: 40 allocated');

        $record1 = $wholesaleOut->records->where('record_id', $this->record1->id)->first();
        $this->assertEquals(0, $record1->backorderRecords()->count(), 'Record 1: no backorder');

        // Assert Record 2: all available stock allocated (75), backorder for remainder (25)
        $finalStockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals(0, $finalStockRecord2, 'Record 2: all 75 allocated');

        $record2 = $wholesaleOut->records->where('record_id', $this->record2->id)->first();
        $backorderQty = $record2->backorderRecords()->sum('quantity');
        $this->assertEquals(25, $backorderQty, 'Record 2: backorder for 25 units');
    }
}
