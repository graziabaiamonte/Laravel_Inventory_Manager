<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Stock;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class B1b_QuantityIncreaseFromNonDefaultAreaTest extends WholesaleOutTestCase
{
    /**
     * Test quantity increase when record is allocated from a NON-DEFAULT area (different from WO default)
     * This catches the bug where quantity increase tried to allocate from WO->area_id
     * instead of the originally allocated area
     */
    public function test_quantity_increase_from_non_default_area(): void
    {
        // Create WholesaleOut with default area = area1 (but we'll allocate from area2)
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id, // WO default is area1
            'status' => 0, // Initially inactive
        ]);

        // Create record and manually allocate from area2 (NOT the WO default area)
        // Area2 starts with 50 units, so allocate 30
        $record = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'quantity' => 30,
        ]);

        // Activate and allocate from area2
        $wholesaleOut->status = 1;
        $wholesaleOut->save();

        // Manually allocate from area2 (not the WO's default area1)
        $record->wholesaleOutRecordsArea()->create([
            'area_id' => $this->area2->id, // Allocate from area2
            'quantity' => 30,
        ]);
        $record->shipped_quantity = 30;
        $record->save();

        // Decrement stock from area2
        $stockArea2 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first();
        $stockArea2->decrement('quantity', 30);

        // Verify initial state
        $this->assertEquals(100, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity, 'Area1 should have 100 (untouched)');
        $this->assertEquals(20, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area2->id)->first()->quantity, 'Area2 should have 20 (50 - 30)');

        // Now increase quantity from 30 to 50
        // Bug: Would try to allocate from area1 (WO default) which has stock
        // Correct: Should allocate from area2 (originally allocated area)
        // After restore: area2 will have 50 (20 + 30), can allocate all 50
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
                    'quantity' => 50,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 50,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area2->id, 'quantity' => 50], // Explicitly area2
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify final stock levels:
        // The restore+reallocate logic should:
        // 1. Restore 30 to area2 (area2 = 20 + 30 = 50)
        // 2. Allocate 50 from area2 (area2 = 50 - 50 = 0)
        // Area1 should be UNTOUCHED (still 100)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(100, $finalArea1Stock, 'Area 1 should be untouched (still 100)');
        $this->assertEquals(0, $finalArea2Stock, 'Area 2 should be 0 (restored 30, then allocated 50)');

        // Verify record has correct final state
        $record->refresh();
        $this->assertEquals(50, $record->quantity, 'Record quantity should be updated to 50');
        $this->assertEquals(50, $record->shipped_quantity, 'Record shipped_quantity should be 50 (all allocated)');

        // Verify area assignment is still area2
        $areaAssignment = $record->wholesaleOutRecordsArea()->first();
        $this->assertNotNull($areaAssignment, 'Area assignment should exist');
        $this->assertEquals($this->area2->id, $areaAssignment->area_id, 'Area assignment should still be area2');
        $this->assertEquals(50, $areaAssignment->quantity, 'Area assignment should be 50');

        // No backorder should be created (stock was sufficient)
        $this->assertEquals(0, $record->backorderRecords()->count(), 'No backorder should exist');
    }

    /**
     * Test quantity increase with partial stock from non-default area
     * Should create backorder for missing units
     */
    public function test_quantity_increase_from_non_default_area_with_partial_stock(): void
    {
        // Create WholesaleOut with default area = area1 (but we'll allocate from area2)
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id, // WO default is area1
            'status' => 0,
        ]);

        // Create record and manually allocate from area2
        // Area2 starts with 50, allocate 30
        $record = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'quantity' => 30,
        ]);

        $wholesaleOut->status = 1;
        $wholesaleOut->save();

        // Allocate from area2
        $record->wholesaleOutRecordsArea()->create([
            'area_id' => $this->area2->id,
            'quantity' => 30,
        ]);
        $record->shipped_quantity = 30;
        $record->save();

        $stockArea2 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first();
        $stockArea2->decrement('quantity', 30);

        // Verify initial: area2 has 20 available (50 - 30)
        $this->assertEquals(20, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area2->id)->first()->quantity);

        // Increase quantity to 70 (more than available in area2 after restore)
        // After restore: area2 will have 50 (20 + 30)
        // Request 70, can only allocate 50, backorder 20
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
                    'quantity' => 70,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 70,
                    'discount' => 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area2->id, 'quantity' => 70],
                    ],
                ],
            ],
        ];

        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify: area2 should be 0 (restored 30 to get 50, then allocated all 50)
        // area1 should be untouched (100)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(100, $finalArea1Stock, 'Area 1 should be untouched');
        $this->assertEquals(0, $finalArea2Stock, 'Area 2 should be 0 (allocated all 50 available)');

        // Verify record state
        $record->refresh();
        $this->assertEquals(70, $record->quantity, 'Record quantity should be 70');
        $this->assertEquals(50, $record->shipped_quantity, 'Record shipped_quantity should be 50 (partial)');

        // Verify backorder created for 20 units
        $backorders = $record->backorderRecords;
        $this->assertEquals(1, $backorders->count(), 'Should have 1 backorder');
        $this->assertEquals(20, $backorders->first()->quantity, 'Backorder should be for 20 units');
    }
}
