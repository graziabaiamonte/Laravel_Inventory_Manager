<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Stock;
use App\Models\WholesaleOut;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class B2_QuantityDecreaseTest extends WholesaleOutTestCase
{
    public function test_quantity_decrease_without_area_change(): void
    {
        // Create active WholesaleOut with record allocated from Area 1 (quantity: 30)
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 30,
            ],
        ]);

        // Verify initial state - 30 units should be allocated from area1
        $this->assertEquals(70, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $this->assertEquals(50, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area2->id)->first()->quantity);

        // Get fresh record
        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(30, $record->quantity);

        // Now decrease quantity from 30 to 15 (keeping same area assignment)
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
                    'quantity' => 15,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 15,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 15],
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify final stock levels:
        // Area 1: Should have 15 units restored (70 + 15 = 85)
        // Area 2: Should remain unchanged (50)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(85, $finalArea1Stock, 'Area 1 should have 15 units restored (70 + 15 = 85)');
        $this->assertEquals(50, $finalArea2Stock, 'Area 2 should remain unchanged');

        // Verify record has correct final state
        $updatedRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(15, $updatedRecord->quantity, 'Record quantity should be updated to 15');

        // Verify no backorders were created
        $this->assertEquals(0, $updatedRecord->backorderRecords()->sum('quantity'), 'No backorders should exist');
    }
}
