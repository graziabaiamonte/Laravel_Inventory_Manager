<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Backorder;
use App\Models\BackorderRecord;
use App\Models\Stock;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class E1_IntegrationTest extends WholesaleOutTestCase
{
    public function test_complete_wholesaleout_lifecycle(): void
    {
        // ========================================
        // STEP 1: Create WholesaleOut with record1 (30 units)
        // ========================================
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 30,
            ],
        ]);

        // Verify initial state
        $this->assertEquals(1, $wholesaleOut->records()->count());
        $this->assertEquals(70, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $this->assertEquals(75, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity);

        // ========================================
        // STEP 2: Add record2 (40 units Area1)
        // ========================================
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        $this->put("/wholesale-out/{$wholesaleOut->id}", [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record1->id,
                    'record_id' => $record1->record_id,
                    'quantity' => 30,
                    'unit_price' => $record1->unit_price->getAmount() / 100,
                    'total_price' => ($record1->unit_price->getAmount() / 100) * 30,
                    'discount' => $record1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 30],
                    ],
                ],
                [
                    'record_id' => $this->record2->id,
                    'quantity' => 40,
                    'unit_price' => 25.50,
                    'total_price' => 25.50 * 40,
                    'discount' => 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 40],
                    ],
                ],
            ],
        ])->assertStatus(302);

        // Verify: 2 records, correct stock allocations
        $wholesaleOut->refresh();
        $this->assertEquals(2, $wholesaleOut->records()->count(), 'Step 2: Should have 2 records');
        $this->assertEquals(70, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $this->assertEquals(35, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity);

        // ========================================
        // STEP 3: Increase record1 to 90 (still within stock)
        // ========================================
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);

        $this->put("/wholesale-out/{$wholesaleOut->id}", [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record1->id,
                    'record_id' => $record1->record_id,
                    'quantity' => 90, // Increase from 30 to 90
                    'unit_price' => $record1->unit_price->getAmount() / 100,
                    'total_price' => ($record1->unit_price->getAmount() / 100) * 90,
                    'discount' => $record1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 90],
                    ],
                ],
                [
                    'id' => $record2->id,
                    'record_id' => $record2->record_id,
                    'quantity' => 40,
                    'unit_price' => $record2->unit_price->getAmount() / 100,
                    'total_price' => ($record2->unit_price->getAmount() / 100) * 40,
                    'discount' => $record2->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 40],
                    ],
                ],
            ],
        ])->assertStatus(302);

        // Verify: record1 increased to 90 (no backorder yet)
        $wholesaleOut->refresh();
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(90, $record1->quantity, 'Step 3: Record1 should be 90');
        $this->assertEquals(0, $record1->backorderRecords()->sum('quantity'), 'Step 3: No backorders yet');
        $this->assertEquals(10, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);

        // ========================================
        // STEP 4: Change record2's area Area1 → Area2 (creates backorder for record2)
        // ========================================
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);

        $this->put("/wholesale-out/{$wholesaleOut->id}", [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record1->id,
                    'record_id' => $record1->record_id,
                    'quantity' => 90,
                    'unit_price' => $record1->unit_price->getAmount() / 100,
                    'total_price' => ($record1->unit_price->getAmount() / 100) * 90,
                    'discount' => $record1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 90],
                    ],
                ],
                [
                    'id' => $record2->id,
                    'record_id' => $record2->record_id,
                    'quantity' => 40,
                    'unit_price' => $record2->unit_price->getAmount() / 100,
                    'total_price' => ($record2->unit_price->getAmount() / 100) * 40,
                    'discount' => $record2->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area2->id, 'quantity' => 40], // Changed to Area2
                    ],
                ],
            ],
        ])->assertStatus(302);

        // Verify: record2 stock restored to Area1, backordered from Area2
        $wholesaleOut->refresh();
        $this->assertEquals(10, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity, 'Step 4: Record1 stock unchanged');
        $this->assertEquals(75, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity, 'Step 4: Record2 Area1 stock fully restored');

        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);
        $this->assertEquals(40, $record2->backorderRecords()->sum('quantity'), 'Step 4: Record2 fully backordered');

        // ========================================
        // STEP 5: Decrease record2 to 20 (partial backorder cancellation)
        // ========================================
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);

        $this->put("/wholesale-out/{$wholesaleOut->id}", [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record1->id,
                    'record_id' => $record1->record_id,
                    'quantity' => 90,
                    'unit_price' => $record1->unit_price->getAmount() / 100,
                    'total_price' => ($record1->unit_price->getAmount() / 100) * 90,
                    'discount' => $record1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 90],
                    ],
                ],
                [
                    'id' => $record2->id,
                    'record_id' => $record2->record_id,
                    'quantity' => 20, // Decrease from 40 to 20
                    'unit_price' => $record2->unit_price->getAmount() / 100,
                    'total_price' => ($record2->unit_price->getAmount() / 100) * 20,
                    'discount' => $record2->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area2->id, 'quantity' => 20],
                    ],
                ],
            ],
        ])->assertStatus(302);

        // Verify: record2's backorder reduced from 40 to 20 (LIFO cancellation)
        $wholesaleOut->refresh();
        $record2 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record2);
        $record2->refresh(); // Ensure fresh data including area assignments
        $this->assertEquals(20, $record2->quantity, 'Step 5: Record2 should be 20');
        $this->assertEquals(20, $record2->backorderRecords()->sum('quantity'), 'Step 5: Record2 backorder reduced to 20');

        // CRITICAL: Since 20 requested - 20 backorder = 0 allocated, area assignment should be 0 or deleted
        $record2AreaAssignment = $record2->wholesaleOutRecordsArea()->sum('quantity');
        $this->assertEquals(0, $record2AreaAssignment, 'Step 5: Record2 should have 0 area assignment (all backordered)');

        // ========================================
        // STEP 6: Delete record2 (cancels remaining backorder)
        // ========================================
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);

        $this->put("/wholesale-out/{$wholesaleOut->id}", [
            'customer_id' => $wholesaleOut->customer_id,
            'area_id' => $wholesaleOut->area_id,
            'status' => $wholesaleOut->status,
            'doc_num' => $wholesaleOut->doc_num,
            'total_price' => $wholesaleOut->total_price,
            'records' => [
                [
                    'id' => $record1->id,
                    'record_id' => $record1->record_id,
                    'quantity' => 90,
                    'unit_price' => $record1->unit_price->getAmount() / 100,
                    'total_price' => ($record1->unit_price->getAmount() / 100) * 90,
                    'discount' => $record1->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 90],
                    ],
                ],
                // record2 removed
            ],
        ])->assertStatus(302);

        // ========================================
        // FINAL VERIFICATION
        // ========================================
        $wholesaleOut->refresh();

        // Should have only 1 record
        $this->assertEquals(1, $wholesaleOut->records()->count(), 'Final: Should have 1 record');

        // record1: 90 allocated from Area1 (stock should be 10)
        $this->assertEquals(10, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity, 'Final: Record1 Area1 stock correct');

        // record2: Stock fully restored to Area1 (75 total)
        $this->assertEquals(75, Stock::where('record_id', $this->record2->id)->where('area_id', $this->area1->id)->first()->quantity, 'Final: Record2 Area1 stock at initial level');

        // No backorders should exist
        $this->assertEquals(0, Backorder::where('wholesale_out_id', $wholesaleOut->id)->count(), 'Final: No backorders should exist');

        // No orphaned backorder records
        $this->assertEquals(0, BackorderRecord::whereHas('backorder', function ($query) use ($wholesaleOut) {
            $query->where('wholesale_out_id', $wholesaleOut->id);
        })->count(), 'Final: No orphaned backorder records');

        // Area assignments should be correct
        $record1 = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(90, $record1->wholesaleOutRecordsArea()->sum('quantity'), 'Final: Record1 area assignments correct');
    }
}
