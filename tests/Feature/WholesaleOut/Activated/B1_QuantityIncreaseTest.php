<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Stock;
use App\Models\WholesaleOut;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class B1_QuantityIncreaseTest extends WholesaleOutTestCase
{
    public function test_quantity_increase_without_area_change(): void
    {
        // Create active WholesaleOut with record allocated from Area 1 (quantity: 20)
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 20,
            ],
        ]);

        // Verify initial state - 20 units should be allocated from area1
        $this->assertEquals(80, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $this->assertEquals(50, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area2->id)->first()->quantity);

        // Get fresh record
        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(20, $record->quantity);

        // Now increase quantity from 20 to 35 (keeping same area assignment)
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
                        ['area_id' => $this->area1->id, 'quantity' => 35],
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify final stock levels:
        // Area 1: Should have additional 15 units decremented (80 - 15 = 65)
        // Area 2: Should remain unchanged (50)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(65, $finalArea1Stock, 'Area 1 should have additional 15 units decremented');
        $this->assertEquals(50, $finalArea2Stock, 'Area 2 should remain unchanged');

        // Verify record has correct final state
        $updatedRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(35, $updatedRecord->quantity, 'Record quantity should be updated to 35');
    }
}
