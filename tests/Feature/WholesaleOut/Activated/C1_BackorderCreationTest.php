<?php

namespace Tests\Feature\WholesaleOut\Activated;

use App\Models\Backorder;
use App\Models\BackorderRecord;
use App\Models\BackorderRecordsArea;
use App\Models\Stock;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class C1_BackorderCreationTest extends WholesaleOutTestCase
{
    public function test_backorder_created_when_stock_insufficient(): void
    {
        // Initial stock: Area 1 has 100 units, Area 2 has 50 units
        // Create active WholesaleOut with record allocated from Area 1 (quantity: 20)
        $wholesaleOut = $this->createActiveWholesaleOut([
            [
                'record_id' => $this->record1->id,
                'quantity' => 20,
            ],
        ]);

        // Verify initial state - 20 units allocated from area1
        $this->assertEquals(80, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area1->id)->first()->quantity);
        $this->assertEquals(50, Stock::where('record_id', $this->record1->id)->where('area_id', $this->area2->id)->first()->quantity);

        // Verify no backorders exist yet
        $this->assertEquals(0, Backorder::where('wholesale_out_id', $wholesaleOut->id)->count());

        // Get fresh record
        $record = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(20, $record->quantity);

        // Now increase quantity from 20 to 120
        // Controller will restore the initial 20 units, bringing Area 1 stock back to 100
        // Then try to allocate 120 total: can allocate 100, backorder 20
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
                    'quantity' => 120,
                    'unit_price' => $record->unit_price->getAmount() / 100,
                    'total_price' => ($record->unit_price->getAmount() / 100) * 120,
                    'discount' => $record->discount ?? 0,
                    'vat' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 120],
                    ],
                ],
            ],
        ];

        // Update the WholesaleOut via controller
        $response = $this->put("/wholesale-out/{$wholesaleOut->id}", $updateData);
        $response->assertStatus(302);

        // Verify final stock levels:
        // Area 1: Controller restored 20, then allocated all 100 → stock = 0
        // Area 2: Should remain unchanged (50)
        $finalArea1Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area1->id)->first()->quantity;
        $finalArea2Stock = Stock::where('record_id', $record->record_id)->where('area_id', $this->area2->id)->first()->quantity;

        $this->assertEquals(0, $finalArea1Stock, 'Area 1 should be depleted to 0');
        $this->assertEquals(50, $finalArea2Stock, 'Area 2 should remain unchanged');

        // Verify record has correct final state
        $updatedRecord = $this->getRecordFromWholesaleOut($wholesaleOut, $this->record1);
        $this->assertEquals(120, $updatedRecord->quantity, 'Record quantity should be updated to 120');

        // Verify backorder was created
        $backorder = Backorder::where('wholesale_out_id', $wholesaleOut->id)
            ->where('status', 0) // 0 = pending
            ->first();

        $this->assertNotNull($backorder, 'Backorder should be created');
        $this->assertEquals(0, $backorder->status, 'Backorder status should be pending (0)');

        // Verify backorder record
        $backorderRecord = BackorderRecord::where('backorder_id', $backorder->id)
            ->where('wholesale_out_record_id', $updatedRecord->id)
            ->first();

        $this->assertNotNull($backorderRecord, 'BackorderRecord should be created');
        $this->assertEquals(20, $backorderRecord->quantity, 'Backorder quantity should be 20 (120 requested - 100 available after restore)');

        // Verify backorder area assignment
        $backorderArea = BackorderRecordsArea::where('backorder_records_id', $backorderRecord->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $this->assertNotNull($backorderArea, 'BackorderRecordsArea should be created');
        $this->assertEquals(20, $backorderArea->quantity, 'Backorder area quantity should be 20');

        // Verify that 100 units total were allocated (all available after restore)
        $totalAllocated = $updatedRecord->wholesaleOutRecordsArea()->sum('quantity');
        $this->assertEquals(100, $totalAllocated, 'Total allocated should be 100 units (all available stock in Area 1 after restore)');
    }
}
