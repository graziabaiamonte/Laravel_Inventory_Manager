<?php

namespace Tests\Feature\WholesaleOut;

use App\Models\Stock;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;

class EditDraftTest extends WholesaleOutTestCase
{
    public function test_edit_draft_quantity_does_not_affect_stock(): void
    {
        // Create draft WholesaleOut
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0, // Draft
        ]);

        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 20,
        ]);

        $initialStock = $stockArea1->quantity;

        // Edit draft - change quantity
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0, // Keep as draft
            'records' => [
                [
                    'id' => $wholesaleOut->records->first()->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 50, // Changed from 20 to 50
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 500.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 50,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert stock was NOT affected (still draft)
        $finalStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStock, $finalStock, 'Stock should NOT change for draft edits');

        // Assert quantity was updated in database
        $wholesaleOut->refresh();
        $this->assertEquals(50, $wholesaleOut->records->first()->quantity);
    }

    public function test_edit_draft_area_does_not_affect_stock(): void
    {
        // Create draft WholesaleOut
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0, // Draft
        ]);

        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockArea2 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first();

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 20,
        ]);

        $initialStockArea1 = $stockArea1->quantity;
        $initialStockArea2 = $stockArea2->quantity;

        // Edit draft - change area
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0, // Keep as draft
            'records' => [
                [
                    'id' => $wholesaleOut->records->first()->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea2->id, // Changed to Area 2
                    'quantity' => 20,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 200.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area2->id, // Changed to Area 2
                            'quantity' => 20,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert stock was NOT affected in either area (still draft)
        $finalStockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $finalStockArea2 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area2->id)
            ->first()->quantity;

        $this->assertEquals($initialStockArea1, $finalStockArea1, 'Stock in Area 1 should NOT change');
        $this->assertEquals($initialStockArea2, $finalStockArea2, 'Stock in Area 2 should NOT change');

        // Assert area assignment was saved correctly
        $wholesaleOut->refresh();
        $woRecord = $wholesaleOut->records->first();
        $areaAssignment = $woRecord->wholesaleOutRecordsArea->first();

        $this->assertNotNull($areaAssignment, 'Area assignment should exist for draft');
        $this->assertEquals($this->area2->id, $areaAssignment->area_id, 'Area should be changed to Area 2');
        $this->assertEquals(20, $areaAssignment->quantity, 'Area assignment quantity should be 20');
        $this->assertEquals(1, $woRecord->wholesaleOutRecordsArea->count(), 'Should have exactly one area assignment');
    }

    public function test_edit_draft_add_record_does_not_affect_stock(): void
    {
        // Create draft WholesaleOut
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0, // Draft
        ]);

        $stockRecord1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first();

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockRecord1->id,
            'quantity' => 20,
        ]);

        $initialStockRecord2 = $stockRecord2->quantity;

        // Edit draft - add new record
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0, // Keep as draft
            'records' => [
                [
                    'id' => $wholesaleOut->records->first()->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockRecord1->id,
                    'quantity' => 20,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 200.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 20,
                        ],
                    ],
                ],
                [
                    // New record
                    'record_id' => $this->record2->id,
                    'stock_id' => $stockRecord2->id,
                    'quantity' => 15,
                    'unit_price' => 12.00,
                    'discount' => 0,
                    'total_price' => 180.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 15,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert stock was NOT affected (still draft)
        $finalStockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStockRecord2, $finalStockRecord2, 'Stock should NOT change for draft');

        // Assert record was added
        $wholesaleOut->refresh();
        $this->assertEquals(2, $wholesaleOut->records->count(), 'Should have 2 records');
    }

    public function test_edit_draft_delete_record_does_not_affect_stock(): void
    {
        $stockRecord1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $initialStockRecord1 = $stockRecord1->quantity;
        $initialStockRecord2 = $stockRecord2->quantity;

        // Create the draft through the controller so area assignments are written
        // by production code (for drafts they hold the REQUESTED quantity)
        $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'DRAFT-DELETE-001',
            'status' => 0, // Draft
            'records' => [
                $this->draftRecordPayload($this->record1->id, $stockRecord1->id, 30),
                $this->draftRecordPayload($this->record2->id, $stockRecord2->id, 15),
            ],
        ])->assertRedirect();

        $wholesaleOut = WholesaleOut::latest()->first();
        $keptRecord = $wholesaleOut->records->firstWhere('record_id', $this->record2->id);

        // Remove the first record from the draft
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0, // Keep as draft
            'records' => [
                $this->draftRecordPayload($this->record2->id, $stockRecord2->id, 15, $keptRecord->id),
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // A draft never decremented stock, so removing a record must not credit any back
        $finalStockRecord1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $finalStockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $this->assertEquals(
            $initialStockRecord1,
            $finalStockRecord1,
            'Stock of the removed record should NOT be incremented: a draft never decremented it'
        );
        $this->assertEquals($initialStockRecord2, $finalStockRecord2, 'Stock of the kept record should NOT change');

        // Assert the record was actually removed
        $wholesaleOut->refresh();
        $this->assertEquals(1, $wholesaleOut->records->count(), 'Should have 1 record left');
        $this->assertEquals($this->record2->id, $wholesaleOut->records->first()->record_id);
    }

    public function test_delete_draft_record_while_activating_only_allocates_remaining(): void
    {
        $stockRecord1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $initialStockRecord1 = $stockRecord1->quantity;
        $initialStockRecord2 = $stockRecord2->quantity;

        $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'DRAFT-DELETE-002',
            'status' => 0, // Draft
            'records' => [
                $this->draftRecordPayload($this->record1->id, $stockRecord1->id, 30),
                $this->draftRecordPayload($this->record2->id, $stockRecord2->id, 15),
            ],
        ])->assertRedirect();

        $wholesaleOut = WholesaleOut::latest()->first();
        $keptRecord = $wholesaleOut->records->firstWhere('record_id', $this->record2->id);

        // Remove one record AND activate in the same request: the deletion is
        // evaluated against the previous (draft) status
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 1, // Activate
            'records' => [
                $this->draftRecordPayload($this->record2->id, $stockRecord2->id, 15, $keptRecord->id),
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $finalStockRecord1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $finalStockRecord2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $this->assertEquals(
            $initialStockRecord1,
            $finalStockRecord1,
            'Stock of the removed record should NOT be incremented on activation'
        );
        $this->assertEquals(
            $initialStockRecord2 - 15,
            $finalStockRecord2,
            'Only the surviving record should be allocated on activation'
        );

        $wholesaleOut->refresh();
        $this->assertEquals(1, $wholesaleOut->status);
        $this->assertEquals(1, $wholesaleOut->records->count(), 'Should have 1 record left');
    }

    /**
     * Build a record payload as the UI submits it for a draft WholesaleOut
     */
    private function draftRecordPayload(int $recordId, int $stockId, int $quantity, ?int $id = null): array
    {
        $payload = [
            'record_id' => $recordId,
            'stock_id' => $stockId,
            'quantity' => $quantity,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => $quantity * 10.00,
            'vat' => 22,
            'area_quantities' => [
                [
                    'area_id' => $this->area1->id,
                    'quantity' => $quantity,
                ],
            ],
        ];

        if ($id !== null) {
            $payload['id'] = $id;
        }

        return $payload;
    }

    public function test_activate_draft_allocates_stock(): void
    {
        // Create draft WholesaleOut
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0, // Draft
        ]);

        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 25,
        ]);

        $initialStock = $stockArea1->quantity;

        // Activate the draft
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 1, // Activate
            'records' => [
                [
                    'id' => $wholesaleOut->records->first()->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 25,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 250.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 25,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert stock WAS decremented on activation
        $finalStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStock - 25, $finalStock, 'Stock should be decremented on activation');

        // Assert status changed to active
        $wholesaleOut->refresh();
        $this->assertEquals(1, $wholesaleOut->status);
    }
}
