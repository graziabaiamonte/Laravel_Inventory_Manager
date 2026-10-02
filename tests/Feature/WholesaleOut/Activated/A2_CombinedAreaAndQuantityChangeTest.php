<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Stock;
use App\Models\WholesaleOut;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class A2_CombinedAreaAndQuantityChangeTest extends WholesaleOutTestCase
{
    public function test_combined_area_and_quantity_change(): void
    {
        // Create active WholesaleOut with record allocated from Area 1 (quantity: 20)
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 20,
            ],
        ]);

        // Verify initial state - 20 units should be allocated from area1
        $initialArea1Stock = Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity;
        $initialArea2Stock = Stock::where('record_id', $this->record1->id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(80, $initialArea1Stock);
        $this->assertEquals(50, $initialArea2Stock);

        // Get fresh record
        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(20, $record->quantity);

        // Now change BOTH area (1 → 2) AND quantity (20 → 35)
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
                    'quantity' => 35,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 35,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area2->id, 'quantity' => 35],
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify final stock levels:
        // Area 1: Should restore original 20 units (80 + 20 = 100)
        // Area 2: Should allocate new 35 units (50 - 35 = 15)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(100, $finalArea1Stock, 'Area 1 should have 20 units restored (80 + 20 = 100)');
        $this->assertEquals(15, $finalArea2Stock, 'Area 2 should have 35 units allocated (50 - 35 = 15)');

        // Verify record has correct final state
        $updatedRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(35, $updatedRecord->quantity, 'Record quantity should be updated to 35');

        // Verify area assignment updated to Area 2
        $areaAssignment = $updatedRecord->wholesaleOutRecordsArea->first();
        $this->assertEquals($this->area2->id, $areaAssignment->area_id, 'Area assignment should be Area 2');
        $this->assertEquals(35, $areaAssignment->quantity, 'Area assignment quantity should be 35');

        // Verify no backorders were created (sufficient stock in Area 2)
        $this->assertEquals(0, $updatedRecord->backorderRecords()->sum('quantity'), 'No backorders should exist');
    }
}
