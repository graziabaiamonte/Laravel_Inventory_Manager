<?php

namespace Tests\Feature\WholesaleOut;

use App\Models\Stock;
use App\Models\WholesaleOut;
use Illuminate\Support\Facades\DB;

/**
 * Test that stock restoration uses shipped_quantity instead of requested quantity
 *
 * BUG: When deactivating/deleting a WholesaleOut, stock was being restored by
 * the REQUESTED quantity instead of the SHIPPED quantity, causing over-restoration
 * when backorders existed.
 *
 * Example:
 * - User requests 10 units
 * - Only 7 available → ships 7, backorders 3
 * - OLD BUG: Restoring 10 units (3 too many!)
 * - NEW FIX: Restoring 7 units (correct)
 */
class ShippedQuantityStockRestorationTest extends WholesaleOutTestCase
{
    /**
     * Test that deactivating a WholesaleOut restores shipped quantity, not requested
     */
    public function test_deactivating_restores_shipped_quantity_not_requested(): void
    {
        // Setup: 10 units available in stock
        $initialStock = 10;
        $stock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stock->update(['quantity' => $initialStock]);

        // Step 1: Create ACTIVE WholesaleOut requesting 15 units (more than available)
        $requestedQty = 15;
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-SHIPPED-QTY-001',
            'status' => 1, // Active
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stock->id,
                    'quantity' => $requestedQty,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQty * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQty,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Step 2: Verify initial allocation
        $wholesaleOut = WholesaleOut::latest()->first();
        $this->assertNotNull($wholesaleOut);
        $this->assertEquals(1, $wholesaleOut->status, 'WholesaleOut should be active');

        $woRecord = $wholesaleOut->records->first();
        $this->assertEquals($requestedQty, $woRecord->quantity, 'Requested quantity should be stored');
        $this->assertEquals($initialStock, $woRecord->shipped_quantity, 'Shipped quantity should equal available stock');

        // Verify backorder created for shortfall
        $backorderQty = $woRecord->backorderRecords->sum('quantity');
        $expectedBackorder = $requestedQty - $initialStock;
        $this->assertEquals($expectedBackorder, $backorderQty, "Backorder should be {$expectedBackorder} units");

        // Verify stock depleted
        $stock->refresh();
        $this->assertEquals(0, $stock->quantity, 'Stock should be fully allocated');

        // Step 3: Deactivate the WholesaleOut
        $response = $this->actingAs($this->user)->put(route('wholesale-out.update', $wholesaleOut), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-SHIPPED-QTY-001',
            'status' => 0, // Deactivate
            'records' => [
                [
                    'id' => $woRecord->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stock->id,
                    'quantity' => $requestedQty,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQty * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQty,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Step 4: CRITICAL TEST - Verify stock restored by SHIPPED quantity (10), NOT requested (15)
        $stock->refresh();
        $this->assertEquals(
            $initialStock,
            $stock->quantity,
            "Stock should be restored to {$initialStock} (shipped quantity), NOT {$requestedQty} (requested quantity)"
        );
    }

    /**
     * Test that deleting a WholesaleOut restores shipped quantity, not requested
     */
    public function test_deleting_restores_shipped_quantity_not_requested(): void
    {
        // Setup: 8 units available in stock
        $initialStock = 8;
        $stock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stock->update(['quantity' => $initialStock]);

        // Step 1: Create ACTIVE WholesaleOut requesting 12 units (more than available)
        $requestedQty = 12;
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-DELETE-001',
            'status' => 1, // Active
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stock->id,
                    'quantity' => $requestedQty,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQty * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQty,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $wholesaleOut = WholesaleOut::latest()->first();
        $woRecord = $wholesaleOut->records->first();

        // Verify allocation
        $this->assertEquals($initialStock, $woRecord->shipped_quantity, 'Should ship 8 units');
        $this->assertEquals($requestedQty - $initialStock, $woRecord->backorderRecords->sum('quantity'), 'Should backorder 4 units');

        // Verify stock depleted
        $stock->refresh();
        $this->assertEquals(0, $stock->quantity, 'Stock should be fully allocated');

        // Step 2: Delete the WholesaleOut
        $response = $this->actingAs($this->user)->delete(route('wholesale-out.destroy', $wholesaleOut));
        $response->assertRedirect();

        // Step 3: CRITICAL TEST - Verify stock restored by SHIPPED quantity (8), NOT requested (12)
        $stock->refresh();
        $this->assertEquals(
            $initialStock,
            $stock->quantity,
            "Stock should be restored to {$initialStock} (shipped quantity), NOT {$requestedQty} (requested quantity)"
        );
    }

    /**
     * Test that area assignment quantity matches shipped quantity for active WholesaleOuts
     */
    public function test_area_assignment_stores_shipped_quantity_not_requested(): void
    {
        // Setup: 5 units available
        $initialStock = 5;
        $stock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stock->update(['quantity' => $initialStock]);

        // Create ACTIVE WholesaleOut requesting 10 units
        $requestedQty = 10;
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-AREA-QTY-001',
            'status' => 1, // Active
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stock->id,
                    'quantity' => $requestedQty,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQty * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQty,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $wholesaleOut = WholesaleOut::latest()->first();
        $woRecord = $wholesaleOut->records->first();

        // CRITICAL TEST: Area assignment should store SHIPPED quantity (5), not requested (10)
        $areaAssignment = $woRecord->wholesaleOutRecordsArea->first();
        $this->assertEquals(
            $initialStock,
            $areaAssignment->quantity,
            "Area assignment should store shipped quantity ({$initialStock}), NOT requested ({$requestedQty})"
        );

        // Verify shipped_quantity is set correctly
        $this->assertEquals($initialStock, $woRecord->shipped_quantity, 'shipped_quantity should equal available stock');

        // Verify requested quantity is still stored separately
        $this->assertEquals($requestedQty, $woRecord->quantity, 'Requested quantity should still be stored');
    }

    /**
     * Test that legacy records without shipped_quantity calculate on-the-fly and store on update
     */
    public function test_legacy_record_calculates_shipped_quantity_on_fly_then_stores(): void
    {
        // Setup: 7 units available
        $initialStock = 7;
        $stock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stock->update(['quantity' => $initialStock]);

        // Step 1: Create ACTIVE WholesaleOut requesting 10 units
        $requestedQty = 10;
        $response = $this->actingAs($this->user)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-LEGACY-001',
            'status' => 1, // Active
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stock->id,
                    'quantity' => $requestedQty,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQty * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQty,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $wholesaleOut = WholesaleOut::latest()->first();
        $woRecord = $wholesaleOut->records->first();

        // Verify shipped_quantity is populated correctly
        $this->assertEquals($initialStock, $woRecord->shipped_quantity, 'Shipped quantity should be set to 7');

        // Step 2: Simulate legacy data by manually resetting shipped_quantity to 0
        DB::table('wholesale_out_records')
            ->where('id', $woRecord->id)
            ->update(['shipped_quantity' => 0]);

        // Refresh the model to get the updated value from DB
        $woRecord = $woRecord->fresh();

        // Step 3: CRITICAL TEST - Verify accessor calculates shipped_quantity on-the-fly
        // The accessor should calculate: quantity (10) - backorder (3) = 7
        $this->assertEquals(
            $initialStock,
            $woRecord->shipped_quantity,
            'Accessor should calculate shipped quantity as 7 even though DB has 0'
        );

        // Verify DB still has 0
        $dbValue = DB::table('wholesale_out_records')
            ->where('id', $woRecord->id)
            ->value('shipped_quantity');
        $this->assertEquals(0, $dbValue, 'DB should still have 0 (not yet backfilled)');

        // Step 4: Update the WholesaleOut (trigger storage of calculated value)
        $response = $this->actingAs($this->user)->put(route('wholesale-out.update', $wholesaleOut), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-LEGACY-001-UPDATED',
            'status' => 1, // Keep active
            'records' => [
                [
                    'id' => $woRecord->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stock->id,
                    'quantity' => $requestedQty,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQty * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQty,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Step 5: Verify shipped_quantity is now stored in DB
        $woRecord = $woRecord->fresh();
        $dbValue = DB::table('wholesale_out_records')
            ->where('id', $woRecord->id)
            ->value('shipped_quantity');

        $this->assertEquals(
            $initialStock,
            $dbValue,
            'After update, shipped_quantity should be stored in DB as 7'
        );

        $this->assertEquals(
            $initialStock,
            $woRecord->shipped_quantity,
            'Accessor should return 7 from DB'
        );

        // Step 6: Verify deactivating now uses the stored shipped_quantity
        $response = $this->actingAs($this->user)->put(route('wholesale-out.update', $wholesaleOut), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-LEGACY-001-UPDATED',
            'status' => 0, // Deactivate
            'records' => [
                [
                    'id' => $woRecord->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stock->id,
                    'quantity' => $requestedQty,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => $requestedQty * 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => $requestedQty,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Verify stock restored by shipped quantity (7), not requested (10)
        $stock->refresh();
        $this->assertEquals(
            $initialStock,
            $stock->quantity,
            'Stock should be restored to 7 (shipped quantity)'
        );
    }
}
