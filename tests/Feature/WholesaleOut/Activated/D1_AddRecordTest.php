<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Stock;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class D1_AddRecordTest extends WholesaleOutTestCase
{
    public function test_add_new_record_to_existing_wholesale_out(): void
    {
        // Create active WholesaleOut with one record (record1: 20 units)
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 20,
            ],
        ]);

        // Verify initial state
        $this->assertEquals(1, $wholesaleOut->records()->count(), 'WholesaleOut should have 1 record');
        $this->assertEquals(80, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $this->assertEquals(75, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity, 'Record2 stock should be 75 (initial stock)');

        // Get the existing record
        $existingRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        // Now add a second record (record2: 30 units)
        $updateData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                // Existing record (unchanged)
                [
                    'id' => $existingRecord->id,
                    'record_id' => $existingRecord->record_id,
                    'quantity' => $existingRecord->quantity,
                    'unit_price' => $existingRecord->unit_price->getAmount() / 100,
                    'total_price' => ($existingRecord->unit_price->getAmount() / 100) * $existingRecord->quantity,
                    'discount' => $existingRecord->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => $existingRecord->quantity],
                    ],
                ],
                // New record
                [
                    'record_id' => $this->record2->id,
                    'quantity' => 30,
                    'unit_price' => 25.50,
                    'total_price' => 25.50 * 30,
                    'discount' => 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 30],
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller (adds new record)
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify WholesaleOut now has 2 records
        $wholesaleOut->refresh();
        $this->assertEquals(2, $wholesaleOut->records()->count(), 'WholesaleOut should now have 2 records');

        // Verify stock for record1 is unchanged
        $finalRecord1Stock = Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity;
        $this->assertEquals(80, $finalRecord1Stock, 'Record1 stock should remain at 80');

        // Verify stock for record2 was allocated (75 - 30 = 45)
        $finalRecord2Stock = Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity;
        $this->assertEquals(45, $finalRecord2Stock, 'Record2 stock should be decremented by 30 (75 - 30 = 45)');

        // Verify both records exist in the WholesaleOut
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);

        $this->assertNotNull($record1, 'Record1 should exist');
        $this->assertNotNull($record2, 'Record2 should exist');
        $this->assertEquals(20, $record1->quantity, 'Record1 quantity should be 20');
        $this->assertEquals(30, $record2->quantity, 'Record2 quantity should be 30');

        // Verify area assignments for both records
        $this->assertEquals(20, $record1->wholesaleOutRecordsArea()->sum('quantity'), 'Record1 area assignment should be 20');
        $this->assertEquals(30, $record2->wholesaleOutRecordsArea()->sum('quantity'), 'Record2 area assignment should be 30');

        // Verify no backorders were created
        $this->assertEquals(0, $record1->backorderRecords()->sum('quantity'), 'Record1 should have no backorders');
        $this->assertEquals(0, $record2->backorderRecords()->sum('quantity'), 'Record2 should have no backorders');
    }

    public function test_add_new_record_with_insufficient_stock_creates_backorder(): void
    {
        // Create active WholesaleOut with one record
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 20,
            ],
        ]);

        // Verify initial state - record2 has 75 units in stock
        $this->assertEquals(75, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity);

        $existingRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        // Now add a second record requesting 90 units (but only 75 available for record2)
        $updateData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                // Existing record (unchanged)
                [
                    'id' => $existingRecord->id,
                    'record_id' => $existingRecord->record_id,
                    'quantity' => $existingRecord->quantity,
                    'unit_price' => $existingRecord->unit_price->getAmount() / 100,
                    'total_price' => ($existingRecord->unit_price->getAmount() / 100) * $existingRecord->quantity,
                    'discount' => $existingRecord->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => $existingRecord->quantity],
                    ],
                ],
                // New record requesting more than available (90 requested, 75 available)
                [
                    'record_id' => $this->record2->id,
                    'quantity' => 90,
                    'unit_price' => 25.50,
                    'total_price' => 25.50 * 90,
                    'discount' => 0,
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

        // Verify WholesaleOut now has 2 records
        $wholesaleOut->refresh();
        $this->assertEquals(2, $wholesaleOut->records()->count(), 'WholesaleOut should now have 2 records');

        // Verify stock for record2 was fully allocated (all 75 units used)
        $finalRecord2Stock = Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity;
        $this->assertEquals(0, $finalRecord2Stock, 'Record2 stock should be depleted to 0 (75 allocated)');

        // Verify the new record
        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);
        $this->assertNotNull($record2, 'Record2 should exist');
        $this->assertEquals(90, $record2->quantity, 'Record2 quantity should be 90');

        // Verify area assignment shows only allocated quantity (75)
        $this->assertEquals(75, $record2->wholesaleOutRecordsArea()->sum('quantity'), 'Record2 area assignment should be 75 (actually allocated)');

        // Verify backorder was created for the shortfall (15 units)
        $backorderQuantity = $record2->backorderRecords()->sum('quantity');
        $this->assertEquals(15, $backorderQuantity, 'Record2 should have backorder of 15 units (90 requested - 75 allocated)');
    }
}
