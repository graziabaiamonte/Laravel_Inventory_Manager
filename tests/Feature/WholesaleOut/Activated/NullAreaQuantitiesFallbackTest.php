<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Stock;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class NullAreaQuantitiesFallbackTest extends WholesaleOutTestCase
{
    /**
     * Test that null/empty area_quantities defaults to the WholesaleOut's area_id
     *
     * This tests the fallback functionality added to prevent records from having
     * no area assignment when the frontend sends null or empty area_quantities.
     */
    public function test_null_area_quantities_defaults_to_wholesale_out_area()
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

        // Verify initial state
        $initialAreaAssignment = $woRecord->wholesaleOutRecordsArea->first();
        $this->assertEquals($this->area1->id, $initialAreaAssignment->area_id);
        $this->assertEquals($initialQuantity, $initialAreaAssignment->quantity);

        // Capture initial stock
        $stockArea1_before = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()
            ->quantity;

        // 2. Action: Update with null area_quantities (simulating frontend bug/omission)
        $newQuantity = 30;
        $updateData = [
            'customer_id' => $wo->customer_id,
            'area_id' => $wo->area_id,
            'status' => $wo->status,
            'doc_num' => $wo->doc_num,
            'total_price' => 1000 * $newQuantity,
            'records' => [
                [
                    'id' => $woRecord->id,
                    'record_id' => $this->record1->id,
                    'quantity' => $newQuantity,
                    'unit_price' => 1000,
                    'total_price' => 1000 * $newQuantity,
                    'discount' => 0,
                    'vat' => 0,
                    // area_quantities is intentionally omitted (null/empty)
                ],
            ],
        ];

        $response = $this->putJson(route('wholesale-out.update', $wo), $updateData);
        $response->assertStatus(302);

        $wo->refresh();
        $woRecord = $this->getRecordFromWholesaleOut($wo, $this->record1);

        // 3. Assertions: Should fallback to WholesaleOut's area_id
        $areaAssignment = $woRecord->wholesaleOutRecordsArea->first();

        $this->assertNotNull($areaAssignment, 'Area assignment should exist (not null)');
        $this->assertEquals(
            $wo->area_id,
            $areaAssignment->area_id,
            'Area should default to WholesaleOut area_id when area_quantities is null/empty'
        );
        $this->assertEquals(
            $newQuantity,
            $areaAssignment->quantity,
            'Area assignment quantity should match record quantity'
        );

        // 4. Verify stock was reconciled correctly
        $stockArea1_after = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()
            ->quantity;

        $expectedStockChange = $newQuantity - $initialQuantity; // 30 - 20 = 10 additional allocation
        $this->assertEquals(
            $stockArea1_before - $expectedStockChange,
            $stockArea1_after,
            'Stock should be reconciled correctly from the default area'
        );
    }

    /**
     * Test that empty array area_quantities also defaults to WholesaleOut's area_id
     */
    public function test_empty_array_area_quantities_defaults_to_wholesale_out_area()
    {
        // 1. Setup
        $initialQuantity = 15;
        $wo = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => $initialQuantity,
                'unit_price' => 500,
                'discount' => 0,
            ],
        ]);

        $woRecord = $this->getRecordFromWholesaleOut($wo, $this->record1);

        // 2. Action: Update with empty array area_quantities
        $newQuantity = 25;
        $updateData = [
            'customer_id' => $wo->customer_id,
            'area_id' => $wo->area_id,
            'status' => $wo->status,
            'doc_num' => $wo->doc_num,
            'total_price' => 500 * $newQuantity,
            'records' => [
                [
                    'id' => $woRecord->id,
                    'record_id' => $this->record1->id,
                    'quantity' => $newQuantity,
                    'unit_price' => 500,
                    'total_price' => 500 * $newQuantity,
                    'discount' => 0,
                    'vat' => 0,
                    'area_quantities' => [], // Explicitly empty array
                ],
            ],
        ];

        $response = $this->putJson(route('wholesale-out.update', $wo), $updateData);
        $response->assertStatus(302);

        $wo->refresh();
        $woRecord = $this->getRecordFromWholesaleOut($wo, $this->record1);

        // 3. Assertions
        $areaAssignment = $woRecord->wholesaleOutRecordsArea->first();

        $this->assertNotNull($areaAssignment, 'Area assignment should exist with empty array');
        $this->assertEquals(
            $wo->area_id,
            $areaAssignment->area_id,
            'Area should default to WholesaleOut area_id with empty array'
        );
        $this->assertEquals($newQuantity, $areaAssignment->quantity);
    }
}
