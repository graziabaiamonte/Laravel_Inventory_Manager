<?php

namespace Tests\Feature;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Location;
use App\Models\Record;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('skip')]
class RecordStockPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $manager;

    protected User $operator;

    protected Location $location1;

    protected Location $location2;

    protected Area $area1;

    protected Area $area2;

    protected Record $record;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        $this->artisan('db:seed', ['--class' => 'RolesPermissionsSeeder']);

        // Create reference data needed by factories
        \App\Models\Format::factory()->create();
        \App\Models\Label::factory()->create();
        \App\Models\Artist::factory()->create();

        // Create users
        $this->admin = User::factory()->create();
        $this->admin->assignRole(RolesEnum::Admin->value);

        $this->manager = User::factory()->create();
        $this->manager->assignRole(RolesEnum::Manager->value);

        $this->operator = User::factory()->create();
        $this->operator->assignRole(RolesEnum::Operator->value);

        // Create locations
        $this->location1 = Location::factory()->create([
            'name' => 'Location 1',
            'type' => LocationTypeEnum::WAREHOUSE,
        ]);

        $this->location2 = Location::factory()->create([
            'name' => 'Location 2',
            'type' => LocationTypeEnum::WAREHOUSE,
        ]);

        // Create areas
        $this->area1 = Area::factory()->create(['name' => 'Area 1']);
        $this->area2 = Area::factory()->create(['name' => 'Area 2']);

        // Link areas to locations
        $this->location1->areas()->attach($this->area1->id);
        $this->location2->areas()->attach($this->area2->id);

        // Assign manager and operator only to location1
        $this->manager->locations()->attach($this->location1->id);
        $this->operator->locations()->attach($this->location1->id);

        // Create a record
        $this->record = Record::factory()->create([
            'rr_uid' => 'TEST-001',
            'title' => 'Test Record',
        ]);

        // Create stock in area1 (location1)
        Stock::factory()->create([
            'record_id' => $this->record->id,
            'area_id' => $this->area1->id,
            'quantity' => 50,
        ]);
    }

    public function test_manager_can_see_all_records(): void
    {
        // Create another record with stock only in location2
        $record2 = Record::factory()->create([
            'rr_uid' => 'TEST-002',
            'title' => 'Test Record 2',
        ]);
        Stock::factory()->create([
            'record_id' => $record2->id,
            'area_id' => $this->area2->id,
            'quantity' => 30,
        ]);

        $response = $this->actingAs($this->manager)->get(route('record.index'));

        $response->assertOk();
        // Manager should see both records, even if they only have access to location1
        $props = $response->viewData('page')['props'];
        $records = $props['records']['data'];
        $this->assertCount(2, $records);
    }

    public function test_operator_can_see_all_records(): void
    {
        // Create another record with stock only in location2
        $record2 = Record::factory()->create([
            'rr_uid' => 'TEST-002',
            'title' => 'Test Record 2',
        ]);
        Stock::factory()->create([
            'record_id' => $record2->id,
            'area_id' => $this->area2->id,
            'quantity' => 30,
        ]);

        $response = $this->actingAs($this->operator)->get(route('record.index'));

        $response->assertOk();
        // Operator should see both records, even if they only have access to location1
        $props = $response->viewData('page')['props'];
        $records = $props['records']['data'];
        $this->assertCount(2, $records);
    }

    public function test_manager_can_add_stock_in_their_location(): void
    {
        $response = $this->actingAs($this->manager)->post(route('record.store'), [
            'title' => 'New Record',
            'artist_id' => $this->record->artist_id,
            'label_id' => $this->record->label_id,
            'format_id' => $this->record->format_id,
            'type' => 'new',
            'wholesale_price' => 0,
            'disk_status' => 0,
            'cover_status' => 0,
            'for_sale_on_discogs' => 0,
            'stock' => [
                [
                    'area_id' => $this->area1->id, // Manager has access to location1/area1
                    'quantity' => 10,
                    'description' => 'Test stock',
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert record was created (rr_uid is auto-generated, so check by title)
        $newRecord = Record::where('title', 'New Record')->first();
        $this->assertNotNull($newRecord);

        // Assert stock was created
        $this->assertDatabaseHas('stocks', [
            'record_id' => $newRecord->id,
            'area_id' => $this->area1->id,
            'quantity' => 10,
        ]);
    }

    public function test_manager_cannot_add_stock_outside_their_location(): void
    {
        $response = $this->actingAs($this->manager)->post(route('record.store'), [
            'title' => 'New Record',
            'artist_id' => $this->record->artist_id,
            'label_id' => $this->record->label_id,
            'format_id' => $this->record->format_id,
            'type' => 'new',
            'wholesale_price' => 0,
            'disk_status' => 0,
            'cover_status' => 0,
            'for_sale_on_discogs' => 0,
            'stock' => [
                [
                    'area_id' => $this->area2->id, // Manager does NOT have access to location2/area2
                    'quantity' => 10,
                    'description' => 'Test stock',
                ],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Non hai il permesso di modificare lo stock', $response->exception->getMessage());
    }

    public function test_operator_can_edit_stock_in_their_location(): void
    {
        $response = $this->actingAs($this->operator)->put(route('record.update', $this->record), [
            'rr_uid' => $this->record->rr_uid,
            'title' => $this->record->title,
            'artist_id' => $this->record->artist_id,
            'label_id' => $this->record->label_id,
            'format_id' => $this->record->format_id,
            'type' => $this->record->type,
            'wholesale_price' => 10.00,
            'disk_status' => $this->record->disk_status ?? 0,
            'cover_status' => $this->record->cover_status ?? 0,
            'for_sale_on_discogs' => $this->record->for_sale_on_discogs ?? 0,
            'stock' => [
                [
                    'area_id' => $this->area1->id, // Operator has access to location1/area1
                    'quantity' => 75, // Changed from 50 to 75
                    'description' => 'Updated stock',
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert stock was updated
        $this->assertDatabaseHas('stocks', [
            'record_id' => $this->record->id,
            'area_id' => $this->area1->id,
            'quantity' => 75,
        ]);
    }

    public function test_operator_cannot_edit_stock_outside_their_location(): void
    {
        // Create stock in area2 (location2) that operator doesn't have access to
        Stock::factory()->create([
            'record_id' => $this->record->id,
            'area_id' => $this->area2->id,
            'quantity' => 20,
        ]);

        $response = $this->actingAs($this->operator)->put(route('record.update', $this->record), [
            'rr_uid' => $this->record->rr_uid,
            'title' => $this->record->title,
            'artist_id' => $this->record->artist_id,
            'label_id' => $this->record->label_id,
            'format_id' => $this->record->format_id,
            'type' => $this->record->type,
            'wholesale_price' => 10.00,
            'disk_status' => $this->record->disk_status ?? 0,
            'cover_status' => $this->record->cover_status ?? 0,
            'for_sale_on_discogs' => $this->record->for_sale_on_discogs ?? 0,
            'stock' => [
                [
                    'area_id' => $this->area2->id, // Operator does NOT have access to location2/area2
                    'quantity' => 40, // Trying to change from 20 to 40
                    'description' => 'Unauthorized update',
                ],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Non hai il permesso di modificare lo stock', $response->exception->getMessage());

        // Assert stock was NOT updated
        $this->assertDatabaseHas('stocks', [
            'record_id' => $this->record->id,
            'area_id' => $this->area2->id,
            'quantity' => 20, // Still the original value
        ]);
    }

    public function test_admin_can_modify_stock_in_any_location(): void
    {
        // Create stock in both areas
        Stock::factory()->create([
            'record_id' => $this->record->id,
            'area_id' => $this->area2->id,
            'quantity' => 20,
        ]);

        $response = $this->actingAs($this->admin)->put(route('record.update', $this->record), [
            'rr_uid' => $this->record->rr_uid,
            'title' => $this->record->title,
            'artist_id' => $this->record->artist_id,
            'label_id' => $this->record->label_id,
            'format_id' => $this->record->format_id,
            'type' => $this->record->type,
            'wholesale_price' => 10.00,
            'disk_status' => $this->record->disk_status ?? 0,
            'cover_status' => $this->record->cover_status ?? 0,
            'for_sale_on_discogs' => $this->record->for_sale_on_discogs ?? 0,
            'stock' => [
                [
                    'area_id' => $this->area1->id,
                    'quantity' => 100,
                    'description' => 'Admin update area1',
                ],
                [
                    'area_id' => $this->area2->id,
                    'quantity' => 200,
                    'description' => 'Admin update area2',
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Assert both stocks were updated
        $this->assertDatabaseHas('stocks', [
            'record_id' => $this->record->id,
            'area_id' => $this->area1->id,
            'quantity' => 100,
        ]);

        $this->assertDatabaseHas('stocks', [
            'record_id' => $this->record->id,
            'area_id' => $this->area2->id,
            'quantity' => 200,
        ]);
    }
}
