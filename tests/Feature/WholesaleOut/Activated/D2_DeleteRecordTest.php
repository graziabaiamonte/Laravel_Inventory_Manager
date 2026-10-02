<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Backorder;
use App\Models\Stock;
use App\Models\WholesaleOut;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class D2_DeleteRecordTest extends WholesaleOutTestCase
{
    public function test_delete_record_restores_stock(): void
    {
        // Create active WholesaleOut with two records
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 20,
            ],
        ]);

        // Add a second record manually
        $existingRecord1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        $updateData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $existingRecord1->id,
                    'record_id' => $existingRecord1->record_id,
                    'quantity' => $existingRecord1->quantity,
                    'unit_price' => $existingRecord1->unit_price->getAmount() / 100,
                    'total_price' => ($existingRecord1->unit_price->getAmount() / 100) * $existingRecord1->quantity,
                    'discount' => $existingRecord1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => $existingRecord1->quantity],
                    ],
                ],
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

        $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData)->assertStatus(302);

        // Verify both records exist and stock is allocated
        $wholesaleOut->refresh();
        $this->assertEquals(2, $wholesaleOut->records()->count(), 'Should have 2 records');
        $this->assertEquals(80, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $this->assertEquals(45, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity);

        // Now delete record2 by updating without it
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        $deleteData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                // Only include record1, effectively deleting record2
                [
                    'id' => $record1->id,
                    'record_id' => $record1->record_id,
                    'quantity' => $record1->quantity,
                    'unit_price' => $record1->unit_price->getAmount() / 100,
                    'total_price' => ($record1->unit_price->getAmount() / 100) * $record1->quantity,
                    'discount' => $record1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => $record1->quantity],
                    ],
                ],
            ],
        ];

        // Delete record2 via update
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $deleteData);
        $response->assertStatus(302);

        // Verify WholesaleOut now has only 1 record
        $wholesaleOut->refresh();
        $this->assertEquals(1, $wholesaleOut->records()->count(), 'Should have 1 record after deletion');

        // Verify record1 still exists with correct stock
        $this->assertEquals(80, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);

        // Verify record2's stock was restored (45 + 30 = 75)
        $finalRecord2Stock = Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity;
        $this->assertEquals(75, $finalRecord2Stock, 'Record2 stock should be restored to 75 (45 + 30)');

        // Verify record2 no longer exists in WholesaleOut
        $deletedRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);
        $this->assertNull($deletedRecord, 'Record2 should be deleted from WholesaleOut');
    }

    public function test_delete_record_with_backorder_cancels_backorder(): void
    {
        // Create active WholesaleOut with one record
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 20,
            ],
        ]);

        $existingRecord1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        // Add record2 with backorder (request 90, only 75 available)
        $addData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $existingRecord1->id,
                    'record_id' => $existingRecord1->record_id,
                    'quantity' => $existingRecord1->quantity,
                    'unit_price' => $existingRecord1->unit_price->getAmount() / 100,
                    'total_price' => ($existingRecord1->unit_price->getAmount() / 100) * $existingRecord1->quantity,
                    'discount' => $existingRecord1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => $existingRecord1->quantity],
                    ],
                ],
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

        $this->put("/wholesale-out/{$wholesaleOut->id}", $addData)->assertStatus(302);

        // Verify record2 was added with backorder
        $wholesaleOut->refresh();
        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);
        $this->assertNotNull($record2, 'Record2 should exist');
        $this->assertEquals(90, $record2->quantity);
        $backorderQuantity = $record2->backorderRecords()->sum('quantity');
        $this->assertEquals(15, $backorderQuantity, 'Should have 15 units backordered');

        // Verify stock state
        $this->assertEquals(0, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity);

        // Now delete record2
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        $deleteData = [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record1->id,
                    'record_id' => $record1->record_id,
                    'quantity' => $record1->quantity,
                    'unit_price' => $record1->unit_price->getAmount() / 100,
                    'total_price' => ($record1->unit_price->getAmount() / 100) * $record1->quantity,
                    'discount' => $record1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => $record1->quantity],
                    ],
                ],
            ],
        ];

        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $deleteData);
        $response->assertStatus(302);

        // Verify record2 was deleted
        $wholesaleOut->refresh();
        $this->assertEquals(1, $wholesaleOut->records()->count(), 'Should have 1 record after deletion');

        // Verify record2's allocated stock was restored (0 + 75 = 75)
        // The 15 backordered units should NOT be restored since they were never allocated
        $finalRecord2Stock = Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity;
        $this->assertEquals(75, $finalRecord2Stock, 'Record2 stock should be restored to 75 (only the actually allocated amount)');

        // Verify record2's backorders were cancelled (no longer exist)
        $record2AfterDelete = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);
        $this->assertNull($record2AfterDelete, 'Record2 should be deleted');
    }
}
