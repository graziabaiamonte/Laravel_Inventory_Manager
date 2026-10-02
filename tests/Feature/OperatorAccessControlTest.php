<?php

namespace Tests\Feature;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Location;
use App\Models\Stock;
use App\Models\User;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use Tests\Feature\WholesaleOut\WholesaleOutTestCase;

class OperatorAccessControlTest extends WholesaleOutTestCase
{
    protected User $operatorUser;

    protected User $managerUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create operator user (no edit_wholesaleout_quantities permission)
        $this->operatorUser = User::factory()->create();
        $this->operatorUser->assignRole(RolesEnum::Operator->value);

        // Create manager user (has edit_wholesaleout_quantities permission)
        $this->managerUser = User::factory()->create();
        $this->managerUser->assignRole(RolesEnum::Manager->value);

        // Assign both users to the warehouse locations (so they have access to areas)
        // This is required for area access validation
        $warehouseLocations = Location::where('type', LocationTypeEnum::WAREHOUSE)->get();
        foreach ($warehouseLocations as $location) {
            $this->operatorUser->locations()->attach($location->id);
            $this->managerUser->locations()->attach($location->id);
            $this->user->locations()->attach($location->id);

            // Link areas to locations via area_location pivot table
            // This is required for the validateAreaAccess check
            $location->areas()->syncWithoutDetaching([$this->area1->id, $this->area2->id]);
        }
    }

    public function test_operator_can_create_new_wholesaleout_with_quantities(): void
    {
        // Operator should be able to CREATE new WholesaleOut with quantities
        // because there are no existing values to protect
        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $response = $this->actingAs($this->operatorUser)->post(route('wholesale-out.store'), [
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'OP-TEST-001',
            'status' => 0,
            'records' => [
                [
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 25,
                    'unit_price' => 15.00,
                    'discount' => 0,
                    'total_price' => 375.00,
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

        // Assert WholesaleOut was created
        $this->assertDatabaseHas('wholesale_outs', [
            'doc_num' => 'OP-TEST-001',
            'customer_id' => $this->customer->id,
        ]);

        // Assert record with correct quantity was created
        $wholesaleOut = WholesaleOut::where('doc_num', 'OP-TEST-001')->first();
        $this->assertEquals(25, $wholesaleOut->records->first()->quantity);
    }

    public function test_operator_cannot_edit_quantities_on_existing_wholesaleout(): void
    {
        // Create a draft WholesaleOut as admin
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $wholesaleOutRecord = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 20,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => 200.00,
            'vat' => 22,
        ]);

        // Create initial area assignment
        $wholesaleOutRecord->wholesaleOutRecordsArea()->create([
            'area_id' => $this->area1->id,
            'quantity' => 20,
        ]);

        // Operator tries to edit quantity (should fail)
        $response = $this->actingAs($this->operatorUser)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0,
            'records' => [
                [
                    'id' => $wholesaleOutRecord->id,
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
                            'quantity' => 50, // Changed from 20 to 50
                        ],
                    ],
                ],
            ],
        ]);

        // Should have validation error for quantity modification
        $response->assertSessionHasErrors();
        $errors = session('errors');
        $this->assertTrue(
            $errors->has('records.0.area_quantities.0.quantity'),
            'Should have validation error for quantity field'
        );
        $this->assertStringContainsString(
            "don't have permission to edit quantities",
            $errors->first('records.0.area_quantities.0.quantity')
        );

        // Assert quantity was NOT changed in database
        $wholesaleOut->refresh();
        $this->assertEquals(20, $wholesaleOut->records->first()->quantity);
    }

    public function test_operator_can_edit_non_quantity_fields(): void
    {
        // Create a draft WholesaleOut as admin
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'description' => 'Original description',
        ]);

        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $wholesaleOutRecord = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 20,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => 200.00,
            'vat' => 22,
        ]);

        // Create initial area assignment
        $wholesaleOutRecord->wholesaleOutRecordsArea()->create([
            'area_id' => $this->area1->id,
            'quantity' => 20,
        ]);

        // Operator can edit description, prices, discount (keeping quantities the same)
        $response = $this->actingAs($this->operatorUser)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'description' => 'Updated by operator',
            'status' => 0,
            'records' => [
                [
                    'id' => $wholesaleOutRecord->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 20, // SAME quantity (not changed)
                    'unit_price' => 12.00, // Changed price
                    'discount' => 10, // Changed discount
                    'total_price' => 216.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 20, // SAME quantity (not changed)
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert changes were saved
        $wholesaleOut->refresh();
        $this->assertEquals('Updated by operator', $wholesaleOut->description);
        $this->assertEquals(1200, $wholesaleOut->records->first()->unit_price->getAmount()); // Money object stores cents
        $this->assertEquals(10, $wholesaleOut->records->first()->discount);
        $this->assertEquals(20, $wholesaleOut->records->first()->quantity); // Quantity unchanged
    }

    public function test_manager_can_edit_quantities_on_existing_wholesaleout(): void
    {
        // Create a draft WholesaleOut as admin
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $wholesaleOutRecord = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 20,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => 200.00,
            'vat' => 22,
        ]);

        // Create initial area assignment
        $wholesaleOutRecord->wholesaleOutRecordsArea()->create([
            'area_id' => $this->area1->id,
            'quantity' => 20,
        ]);

        // Manager tries to edit quantity (should succeed)
        $response = $this->actingAs($this->managerUser)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0,
            'records' => [
                [
                    'id' => $wholesaleOutRecord->id,
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
                            'quantity' => 50, // Changed from 20 to 50
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert quantity was changed in database
        $wholesaleOut->refresh();
        $this->assertEquals(50, $wholesaleOut->records->first()->quantity);
    }

    public function test_admin_can_edit_quantities_on_existing_wholesaleout(): void
    {
        // Create a draft WholesaleOut
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        $stockArea1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $wholesaleOutRecord = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1->id,
            'quantity' => 20,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => 200.00,
            'vat' => 22,
        ]);

        // Create initial area assignment
        $wholesaleOutRecord->wholesaleOutRecordsArea()->create([
            'area_id' => $this->area1->id,
            'quantity' => 20,
        ]);

        // Admin (has 'all' permission) edits quantity (should succeed)
        $response = $this->actingAs($this->user)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0,
            'records' => [
                [
                    'id' => $wholesaleOutRecord->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1->id,
                    'quantity' => 75, // Changed from 20 to 75
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 750.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 75, // Changed from 20 to 75
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert quantity was changed in database
        $wholesaleOut->refresh();
        $this->assertEquals(75, $wholesaleOut->records->first()->quantity);
    }

    public function test_operator_can_add_new_records_to_existing_wholesaleout(): void
    {
        // Create a draft WholesaleOut with one record
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        $stockArea1Record1 = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->first();

        $existingRecord = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record1->id,
            'stock_id' => $stockArea1Record1->id,
            'quantity' => 20,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => 200.00,
            'vat' => 22,
        ]);

        $existingRecord->wholesaleOutRecordsArea()->create([
            'area_id' => $this->area1->id,
            'quantity' => 20,
        ]);

        // Get stock for record2
        $stockArea1Record2 = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->first();

        // Operator adds a new record (no existing quantity to protect for new records)
        $response = $this->actingAs($this->operatorUser)->post(route('wholesale-out.update', $wholesaleOut), [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wholesaleOut->doc_num,
            'status' => 0,
            'records' => [
                // Existing record (quantity unchanged)
                [
                    'id' => $existingRecord->id,
                    'record_id' => $this->record1->id,
                    'stock_id' => $stockArea1Record1->id,
                    'quantity' => 20, // Same
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
                // New record (operator can set any quantity for new records)
                [
                    'record_id' => $this->record2->id,
                    'stock_id' => $stockArea1Record2->id,
                    'quantity' => 30, // New record quantity
                    'unit_price' => 15.00,
                    'discount' => 0,
                    'total_price' => 450.00,
                    'vat' => 22,
                    'area_quantities' => [
                        [
                            'area_id' => $this->area1->id,
                            'quantity' => 30,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert both records exist
        $wholesaleOut->refresh();
        $this->assertEquals(2, $wholesaleOut->records->count());

        // Find the newly added record
        $newRecord = $wholesaleOut->records()->where('record_id', $this->record2->id)->first();
        $this->assertNotNull($newRecord);
        $this->assertEquals(30, $newRecord->quantity);
    }
}
