<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Stock;
use App\Models\WholesaleOut;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class A1_AreaChangeWithoutQuantityChangeTest extends WholesaleOutTestCase
{
    /**
     * Test area change without quantity change (A1 scenario)
     */
    public function test_area_change_without_quantity_change()
    {
        // 1. Setup: Create an active WholesaleOut with one record
        $initialQuantity = 20;
        $wo = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => $initialQuantity,
                'unit_price' => 1000,
                'discount' => 0,
            ],
        ]);

        $woRecord = $this->getRecordFromWholesaleOut($wo, $this->record1);

        // Capture initial stock levels
        $stockArea1_before = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()
            ->quantity;
        $stockArea2_before = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first()
            ->quantity;

        // 2. Action: Update the WholesaleOut, changing the area for the record
        $updateData = [
            'customer_id' => $wo->customer_id,
            'area_id' => $wo->area_id,
            'status' => $wo->status,
            'doc_num' => $wo->doc_num,
            'total_price' => $wo->total_price,
            'records' => [
                [
                    'id' => $woRecord->id,
                    'record_id' => $this->record1->id,
                    'quantity' => $initialQuantity, // Quantity is unchanged
                    'unit_price' => 1000,
                    'total_price' => 1000 * $initialQuantity, // Add required total_price
                    'discount' => 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area2->id, 'quantity' => $initialQuantity], // Moved to Area 2
                    ],
                ],
            ],
        ];

        // Use Laravel's testing helpers instead of manual controller calls
        $response = $this->putJson(route('wholesale-out.update', $wo), $updateData);

        $response->assertStatus(302); // Expecting redirect after successful update

        $wo->refresh();

        // 3. Assertions
        $stockArea1_after = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()
            ->quantity;
        $stockArea2_after = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first()
            ->quantity;

        $woRecord = $this->getRecordFromWholesaleOut($wo, $this->record1);
        $areaAssignment = $woRecord->wholesaleOutRecordsArea->first();

        // Assert stock was restored to Area 1
        $this->assertEquals(
            $stockArea1_before + $initialQuantity,
            $stockArea1_after,
            'Stock in Area 1 should be restored.'
        );

        // Assert stock was allocated from Area 2
        $this->assertEquals(
            $stockArea2_before - $initialQuantity,
            $stockArea2_after,
            'Stock in Area 2 should be allocated.'
        );

        // Assert area assignment is correct
        $this->assertEquals(
            $this->area2->id,
            $areaAssignment->area_id,
            'Area assignment should be updated to Area 2.'
        );
        $this->assertEquals(
            $initialQuantity,
            $areaAssignment->quantity,
            'Area assignment quantity should be correct.'
        );

        // Assert no backorders were created
        $this->assertEquals(
            0,
            $woRecord->backorderRecords()->sum('quantity'),
            'No backorders should be created.'
        );
    }
}
