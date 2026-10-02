<?php

namespace Tests\Feature\WholesaleIn;

use App\Models\Stock;
use App\Models\WholesaleIn;
use App\Models\WholesaleInRecord;
use App\Models\WholesaleinRecordsArea;

/**
 * Tests for WholesaleIn activation/deactivation and stock management
 */
class ActivationTest extends WholesaleInTestCase
{
    public function test_cannot_delete_active_wholesale_in_with_insufficient_stock(): void
    {
        // Create inactive WholesaleIn first
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'DELETE-INSUFFICIENT',
        ]);

        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        // Activate with 100 units
        $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'DELETE-INSUFFICIENT',
            'description' => 'Test',
            'status' => 1,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 100,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 1000.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 100],
                    ],
                ],
            ],
        ]);

        // Simulate sales - reduce stock so that removing 100 would go negative
        // Initial: 100, Added: 100, Total: 200
        // Simulate we sold 150 units, leaving only 50
        // When we try to remove 100, we'd get -50 (negative!)
        $stock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stock->update(['quantity' => 50]);

        $wholesaleIn->refresh();

        // Try to delete - should fail because removing 100 from 50 would be negative
        $response = $this->actingAs($this->user)
            ->delete(route('wholesale-in.destroy', $wholesaleIn));

        $response->assertRedirect(route('wholesale-in.index'));
        $response->assertSessionHasErrors(['status']);

        // WholesaleIn should still exist
        $this->assertDatabaseHas('wholesale_ins', [
            'id' => $wholesaleIn->id,
            'status' => 1,
        ]);
    }

    public function test_cannot_bulk_delete_when_insufficient_stock(): void
    {
        // Create one inactive WholesaleIn
        $inactive = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'BULK-INACTIVE',
        ]);

        // Create active WholesaleIn with stock that has been partially sold
        $active = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'BULK-ACTIVE',
        ]);

        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        // Activate the second one with 50 units
        $this->actingAs($this->user)->patch(route('wholesale-in.update', $active), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'BULK-ACTIVE',
            'description' => 'Test',
            'status' => 1,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 50,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 500.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 50],
                    ],
                ],
            ],
        ]);

        // Simulate sales - reduce stock so removing 50 would be negative
        // Initial: 100, Added: 50, Total: 150
        // Reduce to 30 (sold 120 units)
        // Removing 50 would result in -20 (negative!)
        $stock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stock->update(['quantity' => 30]);

        // Try to bulk delete both - should fail because active one has insufficient stock
        $response = $this->actingAs($this->user)
            ->delete(route('wholesale-in.destroy', $inactive->id), [
                'ids' => [$inactive->id, $active->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['status']);

        // Both should still exist (bulk delete aborted on error)
        $this->assertDatabaseHas('wholesale_ins', ['id' => $inactive->id]);
        $this->assertDatabaseHas('wholesale_ins', ['id' => $active->id]);
    }

    public function test_can_delete_active_wholesale_in_with_sufficient_stock(): void
    {
        // Create inactive WholesaleIn
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'DELETE-SUFFICIENT',
        ]);

        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        // Activate with 50 units
        $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'DELETE-SUFFICIENT',
            'description' => 'Test',
            'status' => 1,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 50,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 500.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 50],
                    ],
                ],
            ],
        ]);

        // Verify stock was added
        $afterActivation = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStock + 50, $afterActivation);

        $wholesaleIn->refresh();

        // Delete should succeed because we have sufficient stock
        $response = $this->actingAs($this->user)
            ->delete(route('wholesale-in.destroy', $wholesaleIn));

        $response->assertRedirect(route('wholesale-in.index'));
        $response->assertSessionHas('success');

        // WholesaleIn should be deleted
        $this->assertDatabaseMissing('wholesale_ins', [
            'id' => $wholesaleIn->id,
        ]);

        // Stock should be reduced back
        $finalStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStock, $finalStock);
    }

    public function test_can_delete_inactive_wholesale_in(): void
    {
        // Create inactive WholesaleIn
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'INACTIVE-DELETE-TEST',
        ]);

        WholesaleInRecord::factory()->create([
            'wholesale_in_id' => $wholesaleIn->id,
            'record_id' => $this->record1->id,
            'quantity' => 10,
        ]);

        // Delete should succeed
        $response = $this->actingAs($this->user)
            ->delete(route('wholesale-in.destroy', $wholesaleIn));

        $response->assertRedirect(route('wholesale-in.index'));
        $response->assertSessionHas('success');

        // WholesaleIn should be deleted
        $this->assertDatabaseMissing('wholesale_ins', [
            'id' => $wholesaleIn->id,
        ]);
    }

    public function test_create_inactive_wholesale_in_does_not_create_stock(): void
    {
        $initialStockCount = Stock::count();

        $response = $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'WI-001',
            'description' => 'Test WholesaleIn',
            'status' => 0,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 10,
                    'unit_price' => 15.50,
                    'discount' => 0,
                    'total_price' => 155.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 10],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('wholesale_ins', [
            'doc_num' => 'WI-001',
            'status' => 0,
        ]);

        // Inactive WholesaleIn should NOT create new stock
        $this->assertEquals($initialStockCount, Stock::count());
    }

    public function test_create_active_wholesale_in_creates_stock(): void
    {
        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $response = $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'WI-002',
            'description' => 'Test Active WholesaleIn',
            'status' => 1,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 20,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 200.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 20],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Active WholesaleIn SHOULD increment stock
        $finalStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $this->assertEquals($initialStock + 20, $finalStock);
    }

    public function test_activate_inactive_wholesale_in_adds_stock(): void
    {
        // Create inactive WholesaleIn
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        $wholesaleInRecord = WholesaleInRecord::factory()->create([
            'wholesale_in_id' => $wholesaleIn->id,
            'record_id' => $this->record1->id,
            'quantity' => 15,
        ]);

        WholesaleinRecordsArea::create([
            'wholesale_in_records_id' => $wholesaleInRecord->id,
            'area_id' => $this->area1->id,
            'quantity' => 15,
        ]);

        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        // Activate the WholesaleIn
        $response = $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleIn->doc_num,
            'description' => $wholesaleIn->description,
            'status' => 1,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 15,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 150.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 15],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Stock should be incremented
        $finalStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $this->assertEquals($initialStock + 15, $finalStock);
    }

    public function test_deactivate_active_wholesale_in_removes_stock(): void
    {
        // Create active WholesaleIn with stock
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 1,
        ]);

        $wholesaleInRecord = WholesaleInRecord::factory()->create([
            'wholesale_in_id' => $wholesaleIn->id,
            'record_id' => $this->record1->id,
            'quantity' => 25,
        ]);

        WholesaleinRecordsArea::create([
            'wholesale_in_records_id' => $wholesaleInRecord->id,
            'area_id' => $this->area1->id,
            'quantity' => 25,
        ]);

        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        // Deactivate the WholesaleIn
        $response = $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleIn->doc_num,
            'description' => $wholesaleIn->description,
            'status' => 0,
        ]);

        $response->assertRedirect();

        // Stock should be reduced (set to initial - 25)
        $finalStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $this->assertEquals($initialStock - 25, $finalStock);
    }

    public function test_prevents_deactivation_when_stock_would_go_negative(): void
    {
        // Create inactive WholesaleIn first
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'TEST-NEG',
        ]);

        // Get initial stock
        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        // Activate it with 150 units
        $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-NEG',
            'description' => 'Test negative stock',
            'status' => 1,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 150,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 1500.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 150],
                    ],
                ],
            ],
        ]);

        // Verify stock was added
        $afterActivation = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStock + 150, $afterActivation);

        // Manually reduce stock to simulate sales (only 40 left out of 150)
        $stock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();
        $stock->update(['quantity' => $initialStock + 40]);

        // Verify stock was reduced correctly
        $currentStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;
        $this->assertEquals($initialStock + 40, $currentStock);

        // Refresh WholesaleIn to get updated data
        $wholesaleIn->refresh();

        // Try to deactivate - should fail with validation error because we need to remove 150 but only have 140
        $response = $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'TEST-NEG',
            'description' => 'Test negative stock',
            'status' => 0,
        ]);

        // Should redirect back with error message
        $response->assertStatus(302);
        $response->assertSessionHasErrors('status');

        // Verify the error message contains the expected details
        $errors = session('errors');
        $this->assertNotNull($errors);
        $errorMessage = $errors->first('status');
        $this->assertStringContainsString('Impossibile disattivare il carico', $errorMessage);
        $this->assertStringContainsString('Stock insufficiente', $errorMessage);
    }

    public function test_blocks_updates_to_active_wholesale_in(): void
    {
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'status' => 1, // Active
            'doc_num' => 'ORIGINAL',
        ]);

        $response = $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'CHANGED',
            'description' => 'Changed description',
            'status' => 1,
            'records' => [],
        ]);

        $response->assertSessionHasErrors(['status']);

        // Should still have original doc_num
        $wholesaleIn->refresh();
        $this->assertEquals('ORIGINAL', $wholesaleIn->doc_num);
    }

    public function test_allows_updates_to_inactive_wholesale_in(): void
    {
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'status' => 0, // Inactive
            'doc_num' => 'ORIGINAL',
        ]);

        $response = $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'UPDATED',
            'description' => 'Updated description',
            'status' => 0,
            'records' => [],
        ]);

        $response->assertRedirect();

        $wholesaleIn->refresh();
        $this->assertEquals('UPDATED', $wholesaleIn->doc_num);
        $this->assertEquals('Updated description', $wholesaleIn->description);
    }

    public function test_edit_inactive_wholesale_in_with_delete_and_recreate_records(): void
    {
        // Create inactive WholesaleIn with 2 records
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        WholesaleInRecord::factory()->create([
            'wholesale_in_id' => $wholesaleIn->id,
            'record_id' => $this->record1->id,
            'quantity' => 10,
        ]);

        WholesaleInRecord::factory()->create([
            'wholesale_in_id' => $wholesaleIn->id,
            'record_id' => $this->record2->id,
            'quantity' => 20,
        ]);

        $this->assertEquals(2, $wholesaleIn->records()->count());

        // Update with only 1 record (remove record1, keep record2)
        $response = $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleIn->doc_num,
            'description' => $wholesaleIn->description,
            'status' => 0,
            'records' => [
                [
                    'record_id' => $this->record2->id,
                    'quantity' => 25, // Changed quantity
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 250.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 25],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // Should now have only 1 record with updated quantity
        $wholesaleIn->refresh();
        $this->assertEquals(1, $wholesaleIn->records()->count());
        $this->assertEquals(25, $wholesaleIn->records()->first()->quantity);
        $this->assertEquals($this->record2->id, $wholesaleIn->records()->first()->record_id);
    }

    public function test_reactivation_adds_stock_back(): void
    {
        // Create active WholesaleIn
        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 1,
        ]);

        $wiRecord = WholesaleInRecord::factory()->create([
            'wholesale_in_id' => $wholesaleIn->id,
            'record_id' => $this->record1->id,
            'quantity' => 50,
        ]);

        WholesaleinRecordsArea::create([
            'wholesale_in_records_id' => $wiRecord->id,
            'area_id' => $this->area1->id,
            'quantity' => 50,
        ]);

        $initialStock = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        // Deactivate
        $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleIn->doc_num,
            'description' => $wholesaleIn->description,
            'status' => 0,
        ]);

        $stockAfterDeactivation = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $this->assertEquals($initialStock - 50, $stockAfterDeactivation);

        // Reactivate
        $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleIn->doc_num,
            'description' => $wholesaleIn->description,
            'status' => 1,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'quantity' => 50,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 500.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 50],
                    ],
                ],
            ],
        ]);

        $stockAfterReactivation = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first()->quantity;

        $this->assertEquals($initialStock, $stockAfterReactivation);
    }
}
