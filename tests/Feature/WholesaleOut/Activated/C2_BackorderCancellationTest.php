<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Backorder;
use App\Models\BackorderRecord;
use App\Models\Stock;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class C2_BackorderCancellationTest extends WholesaleOutTestCase
{
    public function test_backorder_cancelled_when_quantity_decreases(): void
    {
        // Initial stock: Area 1 has 100 units, Area 2 has 50 units
        // We'll create a scenario where we have 100 allocated + 20 backordered

        // Step 1: Create active WholesaleOut with 100 units (all available stock from Area 1)
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 100,
            ],
        ]);

        // Verify initial allocation - 100 units allocated, Area 1 depleted
        $this->assertEquals(0, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(100, $record->quantity);

        // Step 2: Update to 120 units to create a backorder of 20
        $updateData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record->id,
                    'record_id' => $record->record_id,
                    'quantity' => 120,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 120,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 120],
                    ],
                ],
            ],
        ];

        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify backorder was created
        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(120, $record->quantity);
        $initialBackorderQuantity = $record->backorderRecords()->sum('quantity');
        $this->assertEquals(20, $initialBackorderQuantity, 'Initial backorder should be 20 units');

        // Step 3: Now decrease quantity from 120 to 90
        // This should:
        // 1. Cancel 20 units of backorder (reducing backorder to 0)
        // 2. Restore 10 units to stock (90 allocated vs 100 previously allocated)
        $updateData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record->id,
                    'record_id' => $record->record_id,
                    'quantity' => 90,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 90,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 90],
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify final stock levels:
        // Area 1: Restored 10 units (0 + 10 = 10)
        // Area 2: Should remain unchanged (50)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(10, $finalArea1Stock, 'Area 1 should have 10 units restored');
        $this->assertEquals(50, $finalArea2Stock, 'Area 2 should remain unchanged');

        // Verify record has correct final state
        $updatedRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(90, $updatedRecord->quantity, 'Record quantity should be updated to 90');

        // Verify backorder was completely cancelled
        $finalBackorderQuantity = $updatedRecord->backorderRecords()->sum('quantity');
        $this->assertEquals(0, $finalBackorderQuantity, 'All backorders should be cancelled');

        // Verify backorder record was deleted (not just set to 0)
        $backorderRecordCount = BackorderRecord::where('wholesale_out_record_id', $updatedRecord->id)->count();
        $this->assertEquals(0, $backorderRecordCount, 'BackorderRecord should be deleted');

        // Verify the Backorder parent still exists but may be empty
        $backorder = Backorder::where('wholesale_out_id', $wholesaleOut->id)->first();
        if ($backorder) {
            // If backorder exists, verify it has no records
            $this->assertEquals(0, $backorder->backorderRecords()->count(), 'Backorder should have no records');
        }

        // Verify area assignment shows correct allocated quantity (90)
        $totalAllocated = $updatedRecord->wholesaleOutRecordsArea()->sum('quantity');
        $this->assertEquals(90, $totalAllocated, 'Area assignment should show 90 allocated units');
    }

    public function test_partial_backorder_cancellation(): void
    {
        // Step 1: Create active WholesaleOut with 100 units
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 100,
            ],
        ]);

        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        // Step 2: Update to 120 units to create a backorder of 20
        $updateData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record->id,
                    'record_id' => $record->record_id,
                    'quantity' => 120,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 120,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 120],
                    ],
                ],
            ],
        ];

        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(120, $record->quantity);
        $initialBackorderQuantity = $record->backorderRecords()->sum('quantity');
        $this->assertEquals(20, $initialBackorderQuantity, 'Initial backorder should be 20 units');

        // Step 3: Now decrease quantity from 120 to 110
        // This should:
        // 1. Cancel 10 units of backorder (reducing backorder from 20 to 10)
        // 2. NOT restore any stock (still need 110, have 100 allocated)
        $updateData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record->id,
                    'record_id' => $record->record_id,
                    'quantity' => 110,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 110,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 110],
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify stock levels unchanged (no restoration since we still need more than allocated)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(0, $finalArea1Stock, 'Area 1 stock should remain 0');
        $this->assertEquals(50, $finalArea2Stock, 'Area 2 should remain unchanged');

        // Verify record has correct final state
        $updatedRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(110, $updatedRecord->quantity, 'Record quantity should be updated to 110');

        // Verify backorder was partially cancelled (20 -> 10)
        $finalBackorderQuantity = $updatedRecord->backorderRecords()->sum('quantity');
        $this->assertEquals(10, $finalBackorderQuantity, 'Backorder should be reduced to 10 units');

        // Verify backorder record still exists
        $backorderRecord = BackorderRecord::where('wholesale_out_record_id', $updatedRecord->id)->first();
        $this->assertNotNull($backorderRecord, 'BackorderRecord should still exist');
        $this->assertEquals(10, $backorderRecord->quantity, 'BackorderRecord quantity should be 10');

        // Verify area assignment shows correct allocated quantity (100)
        $totalAllocated = $updatedRecord->wholesaleOutRecordsArea()->sum('quantity');
        $this->assertEquals(100, $totalAllocated, 'Area assignment should still show 100 allocated units');
    }
}
