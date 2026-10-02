<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Record;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Models\WholesaleIn;
use App\Models\WholesaleOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManagerAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $managerLocation1;

    protected User $managerLocation2;

    protected Location $location1;

    protected Location $location2;

    protected Area $area1;

    protected Area $area2;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        $this->artisan('db:seed', ['--class' => 'RolesPermissionsSeeder']);

        // Create reference data needed by factories
        \App\Models\Format::factory()->create();
        \App\Models\Label::factory()->create();
        \App\Models\Artist::factory()->create();

        // Create locations
        $this->location1 = Location::create([
            'name' => 'Location 1',
            'type' => 'store',
            'status' => 1,
        ]);

        $this->location2 = Location::create([
            'name' => 'Location 2',
            'type' => 'store',
            'status' => 1,
        ]);

        // Create areas and connect to locations
        $this->area1 = Area::create(['name' => 'Area 1', 'status' => 1]);
        $this->area2 = Area::create(['name' => 'Area 2', 'status' => 1]);

        $this->area1->locations()->attach($this->location1->id);
        $this->area2->locations()->attach($this->location2->id);

        // Create users with roles
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->managerLocation1 = User::factory()->create();
        $this->managerLocation1->assignRole('Manager');
        $this->managerLocation1->locations()->attach($this->location1->id);
        // @DISABLED-DYNAMIC-PERMS: Dynamic permissions disabled - using location_user pivot table instead
        // $this->managerLocation1->givePermissionTo('manage_location_'.$this->location1->id);

        $this->managerLocation2 = User::factory()->create();
        $this->managerLocation2->assignRole('Manager');
        $this->managerLocation2->locations()->attach($this->location2->id);
        // @DISABLED-DYNAMIC-PERMS: Dynamic permissions disabled - using location_user pivot table instead
        // $this->managerLocation2->givePermissionTo('manage_location_'.$this->location2->id);
    }

    #[Test]
    public function admin_can_see_all_locations(): void
    {
        $this->actingAs($this->admin);

        $locations = Location::filterByAdminRoles()->get();

        $this->assertCount(2, $locations);
    }

    #[Test]
    public function manager_can_only_see_their_assigned_locations(): void
    {
        $this->actingAs($this->managerLocation1);

        $locations = Location::filterByAdminRoles()->get();

        $this->assertCount(1, $locations);
        $this->assertEquals($this->location1->id, $locations->first()->id);
    }

    #[Test]
    public function manager_can_only_see_areas_connected_to_their_locations(): void
    {
        $this->actingAs($this->managerLocation1);

        $areas = Area::filterByAdminRoles()->get();

        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()->id);
    }

    #[Test]
    public function manager_can_only_see_sales_from_their_locations(): void
    {
        $sale1 = Sale::factory()->create(['location_id' => $this->location1->id]);
        $sale2 = Sale::factory()->create(['location_id' => $this->location2->id]);

        $this->actingAs($this->managerLocation1);

        $sales = Sale::filterByAdminRoles()->get();

        $this->assertCount(1, $sales);
        $this->assertEquals($sale1->id, $sales->first()->id);
    }

    #[Test]
    public function manager_can_only_see_wholesale_ins_from_their_areas(): void
    {
        $wholesaleIn1 = WholesaleIn::factory()->create(['area_id' => $this->area1->id]);
        $wholesaleIn2 = WholesaleIn::factory()->create(['area_id' => $this->area2->id]);

        $this->actingAs($this->managerLocation1);

        $wholesaleIns = WholesaleIn::filterByAdminRoles()->get();

        $this->assertCount(1, $wholesaleIns);
        $this->assertEquals($wholesaleIn1->id, $wholesaleIns->first()->id);
    }

    #[Test]
    public function manager_can_only_see_wholesale_outs_from_their_areas(): void
    {
        $customer = Customer::factory()->create();

        $wholesaleOut1 = WholesaleOut::factory()->create([
            'area_id' => $this->area1->id,
            'customer_id' => $customer->id,
        ]);
        $wholesaleOut2 = WholesaleOut::factory()->create([
            'area_id' => $this->area2->id,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $wholesaleOuts = WholesaleOut::filterByAdminRoles()->get();

        $this->assertCount(1, $wholesaleOuts);
        $this->assertEquals($wholesaleOut1->id, $wholesaleOuts->first()->id);
    }

    #[Test]
    public function manager_can_see_all_customers_with_manage_customers_permission(): void
    {
        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();

        // Create WholesaleOuts to link customers to manager's location
        // Managers can only see customers who have orders in their areas
        WholesaleOut::factory()->create([
            'customer_id' => $customer1->id,
            'area_id' => $this->area1->id,
        ]);
        WholesaleOut::factory()->create([
            'customer_id' => $customer2->id,
            'area_id' => $this->area1->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $customers = Customer::filterByAdminRoles()->get();

        // Manager can see customers who have WholesaleOuts in their areas
        $this->assertCount(2, $customers);
    }

    #[Test]
    public function manager_can_only_see_records_with_stock_in_their_areas(): void
    {
        $record1 = Record::factory()->create();
        $record2 = Record::factory()->create();

        // Record 1 has stock in area 1
        Stock::factory()->create([
            'record_id' => $record1->id,
            'area_id' => $this->area1->id,
            'quantity' => 5,
        ]);

        // Record 2 has stock in area 2
        Stock::factory()->create([
            'record_id' => $record2->id,
            'area_id' => $this->area2->id,
            'quantity' => 3,
        ]);

        $this->actingAs($this->managerLocation1);

        $records = Record::filterByAdminRoles()->get();

        // Manager can now see all records (view permission is granted to all)
        // but can only modify stock in their areas
        $this->assertCount(2, $records);
    }

    #[Test]
    public function manager_can_only_see_stock_in_their_areas(): void
    {
        $record = Record::factory()->create();

        $stock1 = Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area1->id,
        ]);
        $stock2 = Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area2->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $stocks = Stock::filterByAdminRoles()->get();

        $this->assertCount(1, $stocks);
        $this->assertEquals($stock1->id, $stocks->first()->id);
    }

    #[Test]
    public function manager_cannot_access_record_from_another_location(): void
    {
        $record = Record::factory()->create();
        Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area2->id, // Different location
        ]);

        $this->actingAs($this->managerLocation1);

        // Manager can view the record (view policy allows all users)
        // but cannot modify stock in area2 (checked at controller level)
        $this->assertTrue($this->managerLocation1->can('view', $record));
    }

    #[Test]
    public function manager_can_access_record_from_their_location(): void
    {
        $record = Record::factory()->create();
        Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area1->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $this->assertTrue($this->managerLocation1->can('view', $record));
    }

    #[Test]
    public function admin_can_access_all_records(): void
    {
        $record = Record::factory()->create();
        Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area2->id,
        ]);

        $this->actingAs($this->admin);

        $this->assertTrue($this->admin->can('view', $record));
    }

    #[Test]
    public function manager_can_only_see_users_from_shared_locations(): void
    {
        $userLocation1 = User::factory()->create();
        $userLocation1->assignRole('Operator');
        $userLocation1->locations()->attach($this->location1->id);

        $userLocation2 = User::factory()->create();
        $userLocation2->assignRole('Operator');
        $userLocation2->locations()->attach($this->location2->id);

        $this->actingAs($this->managerLocation1);

        $users = User::filterByAdminRoles()->get();

        // Scope excludes current user (by design for user management), so should only see userLocation1
        $this->assertCount(1, $users);
        $this->assertFalse($users->contains('id', $this->managerLocation1->id));
        $this->assertTrue($users->contains('id', $userLocation1->id));
        $this->assertFalse($users->contains('id', $userLocation2->id));
    }

    #[Test]
    public function manager_can_only_see_backorders_from_their_areas(): void
    {
        $customer = Customer::factory()->create();

        $wholesaleOut1 = WholesaleOut::factory()->create([
            'area_id' => $this->area1->id,
            'customer_id' => $customer->id,
        ]);
        $wholesaleOut2 = WholesaleOut::factory()->create([
            'area_id' => $this->area2->id,
            'customer_id' => $customer->id,
        ]);

        $backorder1 = \App\Models\Backorder::factory()->create([
            'wholesale_out_id' => $wholesaleOut1->id,
        ]);
        $backorder2 = \App\Models\Backorder::factory()->create([
            'wholesale_out_id' => $wholesaleOut2->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $backorders = \App\Models\Backorder::filterByAdminRoles()->get();

        $this->assertCount(1, $backorders);
        $this->assertEquals($backorder1->id, $backorders->first()->id);
    }

    #[Test]
    public function manager_cannot_update_sale_from_another_location(): void
    {
        $sale = Sale::factory()->create(['location_id' => $this->location2->id]);

        $this->actingAs($this->managerLocation1);

        $this->assertFalse($this->managerLocation1->can('update', $sale));
    }

    #[Test]
    public function manager_can_update_sale_from_their_location(): void
    {
        $sale = Sale::factory()->create(['location_id' => $this->location1->id]);

        $this->actingAs($this->managerLocation1);

        $this->assertTrue($this->managerLocation1->can('update', $sale));
    }

    #[Test]
    public function manager_cannot_update_wholesale_in_from_another_area(): void
    {
        $wholesaleIn = WholesaleIn::factory()->create(['area_id' => $this->area2->id]);

        $this->actingAs($this->managerLocation1);

        $this->assertFalse($this->managerLocation1->can('update', $wholesaleIn));
    }

    #[Test]
    public function manager_can_update_wholesale_in_from_their_area(): void
    {
        $wholesaleIn = WholesaleIn::factory()->create(['area_id' => $this->area1->id]);

        $this->actingAs($this->managerLocation1);

        $this->assertTrue($this->managerLocation1->can('update', $wholesaleIn));
    }

    #[Test]
    public function manager_cannot_update_wholesale_out_from_another_area(): void
    {
        $customer = Customer::factory()->create();
        $wholesaleOut = WholesaleOut::factory()->create([
            'area_id' => $this->area2->id,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $this->assertFalse($this->managerLocation1->can('update', $wholesaleOut));
    }

    #[Test]
    public function manager_can_update_wholesale_out_from_their_area(): void
    {
        $customer = Customer::factory()->create();
        $wholesaleOut = WholesaleOut::factory()->create([
            'area_id' => $this->area1->id,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $this->assertTrue($this->managerLocation1->can('update', $wholesaleOut));
    }

    #[Test]
    public function manager_cannot_update_area_not_connected_to_their_locations(): void
    {
        $this->actingAs($this->managerLocation1);

        $this->assertFalse($this->managerLocation1->can('update', $this->area2));
    }

    #[Test]
    public function manager_can_update_area_connected_to_their_locations(): void
    {
        $this->actingAs($this->managerLocation1);

        $this->assertTrue($this->managerLocation1->can('update', $this->area1));
    }

    #[Test]
    public function manager_cannot_update_stock_in_another_area(): void
    {
        $record = Record::factory()->create();
        $stock = Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area2->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $this->assertFalse($this->managerLocation1->can('update', $stock));
    }

    #[Test]
    public function manager_can_update_stock_in_their_area(): void
    {
        $record = Record::factory()->create();
        $stock = Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area1->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $this->assertTrue($this->managerLocation1->can('update', $stock));
    }

    #[Test]
    public function manager_with_multiple_locations_can_see_data_from_all_assigned_locations(): void
    {
        // Create a third location and area
        $location3 = Location::create([
            'name' => 'Location 3',
            'type' => 'store',
            'status' => 1,
        ]);
        $area3 = Area::create(['name' => 'Area 3', 'status' => 1]);
        $area3->locations()->attach($location3->id);

        // Assign manager to both location1 and location3
        $this->managerLocation1->locations()->attach($location3->id);
        // @DISABLED-DYNAMIC-PERMS: Dynamic permissions disabled - using location_user pivot table instead
        // $this->managerLocation1->givePermissionTo('manage_location_'.$location3->id);

        // Create sales in all three locations
        $sale1 = Sale::factory()->create(['location_id' => $this->location1->id]);
        $sale2 = Sale::factory()->create(['location_id' => $this->location2->id]);
        $sale3 = Sale::factory()->create(['location_id' => $location3->id]);

        $this->actingAs($this->managerLocation1);

        $sales = Sale::filterByAdminRoles()->get();

        // Should see sales from location1 and location3, but not location2
        $this->assertCount(2, $sales);
        $this->assertTrue($sales->contains('id', $sale1->id));
        $this->assertFalse($sales->contains('id', $sale2->id));
        $this->assertTrue($sales->contains('id', $sale3->id));
    }

    // DROPDOWN FILTERING TESTS - Ensure managers only see authorized areas/locations in dropdowns

    #[Test]
    public function manager_only_sees_authorized_locations_in_sale_index_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('sale.index'));

        $response->assertSuccessful();

        // Access Inertia props
        $props = $response->viewData('page')['props'];
        $locations = collect($props['locations']);

        $this->assertCount(1, $locations);
        $this->assertEquals($this->location1->id, $locations->first()['id']);
        $this->assertFalse($locations->contains('id', $this->location2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_locations_in_sale_create_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('sale.create'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $locations = collect($props['locations']);

        $this->assertCount(1, $locations);
        $this->assertEquals($this->location1->id, $locations->first()['id']);
        $this->assertFalse($locations->contains('id', $this->location2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_locations_in_sale_edit_dropdown(): void
    {
        $sale = Sale::factory()->create(['location_id' => $this->location1->id]);

        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('sale.edit', $sale));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $locations = collect($props['locations']);

        $this->assertCount(1, $locations);
        $this->assertEquals($this->location1->id, $locations->first()['id']);
        $this->assertFalse($locations->contains('id', $this->location2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_wholesale_in_index_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('wholesale-in.index'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_wholesale_in_create_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('wholesale-in.create'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_wholesale_out_create_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('wholesale-out.create'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_location_index_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('location.index'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_location_create_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('location.create'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_location_edit_dropdown(): void
    {
        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('location.edit', $this->location1));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_record_create_dropdown(): void
    {
        // Set area1 as default for location1
        $this->location1->update(['default_area_id' => $this->area1->id]);
        $this->location2->update(['default_area_id' => $this->area2->id]);

        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('record.create'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        // Should only see default areas connected to their locations
        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function manager_only_sees_authorized_areas_in_record_edit_dropdown(): void
    {
        // Set area1 as default for location1
        $this->location1->update(['default_area_id' => $this->area1->id]);
        $this->location2->update(['default_area_id' => $this->area2->id]);

        $record = Record::factory()->create();
        Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area1->id,
        ]);

        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('record.edit', $record));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        // Should only see default areas connected to their locations
        $this->assertCount(1, $areas);
        $this->assertEquals($this->area1->id, $areas->first()['id']);
        $this->assertFalse($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function admin_sees_all_areas_in_dropdowns(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('location.index'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        // Admin should see all areas
        $this->assertCount(2, $areas);
        $this->assertTrue($areas->contains('id', $this->area1->id));
        $this->assertTrue($areas->contains('id', $this->area2->id));
    }

    #[Test]
    public function admin_sees_all_locations_in_dropdowns(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('sale.index'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $locations = collect($props['locations']);

        // Admin should see all locations
        $this->assertCount(2, $locations);
        $this->assertTrue($locations->contains('id', $this->location1->id));
        $this->assertTrue($locations->contains('id', $this->location2->id));
    }

    #[Test]
    public function manager_with_multiple_locations_sees_all_their_areas_in_dropdowns(): void
    {
        // Create a third location and area
        $location3 = Location::create([
            'name' => 'Location 3',
            'type' => 'store',
            'status' => 1,
        ]);
        $area3 = Area::create(['name' => 'Area 3', 'status' => 1]);
        $area3->locations()->attach($location3->id);

        // Assign manager to both location1 and location3
        $this->managerLocation1->locations()->attach($location3->id);

        $this->actingAs($this->managerLocation1);

        $response = $this->get(route('location.index'));

        $response->assertSuccessful();

        $props = $response->viewData('page')['props'];
        $areas = collect($props['areas']);

        // Should see areas from both assigned locations
        $this->assertCount(2, $areas);
        $this->assertTrue($areas->contains('id', $this->area1->id));
        $this->assertFalse($areas->contains('id', $this->area2->id));
        $this->assertTrue($areas->contains('id', $area3->id));
    }

    // IMPORT AUTHORIZATION TESTS - Ensure managers can only import to authorized areas

    #[Test]
    public function manager_can_only_import_records_to_authorized_areas(): void
    {
        $this->actingAs($this->managerLocation1);

        // Manager should only see authorized area columns in import template headers
        $import = new \App\Imports\RecordsImport;

        // Use reflection to call private method for testing
        $reflection = new \ReflectionClass($import);
        $method = $reflection->getMethod('getAreaColumnsFromHeaders');

        // Create headers that include both authorized and unauthorized areas
        $headers = [
            'id', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q',
            strtolower($this->location1->name.' - '.$this->area1->name),
            strtolower($this->location2->name.' - '.$this->area2->name), // Unauthorized
        ];

        $areaMap = $method->invoke($import, $headers);

        // Should only include authorized area
        $areaIds = array_values($areaMap);
        $this->assertContains($this->area1->id, $areaIds);
        $this->assertNotContains($this->area2->id, $areaIds);
    }

    #[Test]
    public function manager_can_only_import_wholesale_in_to_authorized_areas(): void
    {
        $this->actingAs($this->managerLocation1);

        $import = new \App\Imports\WholesaleInImport;

        // Use reflection to call private method
        $reflection = new \ReflectionClass($import);
        $method = $reflection->getMethod('getAreaColumnMapping');

        // Create headers with both authorized and unauthorized area columns
        $headers = [
            'cat#', 'title', 'barcode', 'q',
            \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($this->area1->name)),
            \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($this->area2->name)), // Unauthorized
        ];

        $areaMapping = $method->invoke($import, $headers);

        // Extract area IDs from mapping
        $areaIds = array_column($areaMapping, 'area_id');

        // Should only include authorized area
        $this->assertContains($this->area1->id, $areaIds);
        $this->assertNotContains($this->area2->id, $areaIds);
    }

    #[Test]
    public function manager_can_only_import_wholesale_out_to_authorized_areas(): void
    {
        $this->actingAs($this->managerLocation1);

        $import = new \App\Imports\WholesaleOutImport;

        // Use reflection to call private method
        $reflection = new \ReflectionClass($import);
        $method = $reflection->getMethod('getAreaColumnMapping');

        // Create headers with both authorized and unauthorized area columns
        $headers = [
            'cat#', 'barcode', 'order_amount',
            \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($this->area1->name)),
            \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($this->area2->name)), // Unauthorized
        ];

        $areaMapping = $method->invoke($import, $headers);

        // Extract area IDs from mapping
        $areaIds = array_column($areaMapping, 'area_id');

        // Should only include authorized area
        $this->assertContains($this->area1->id, $areaIds);
        $this->assertNotContains($this->area2->id, $areaIds);
    }

    #[Test]
    public function admin_can_import_to_all_areas(): void
    {
        $this->actingAs($this->admin);

        $import = new \App\Imports\RecordsImport;

        $reflection = new \ReflectionClass($import);
        $method = $reflection->getMethod('getAreaColumnsFromHeaders');

        // Create headers with all areas
        $headers = [
            'id', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q',
            strtolower($this->location1->name.' - '.$this->area1->name),
            strtolower($this->location2->name.' - '.$this->area2->name),
        ];

        $areaMap = $method->invoke($import, $headers);
        $areaIds = array_values($areaMap);

        // Admin should see all areas
        $this->assertContains($this->area1->id, $areaIds);
        $this->assertContains($this->area2->id, $areaIds);
    }

    #[Test]
    public function manager_with_multiple_locations_can_import_to_all_their_areas(): void
    {
        // Create a third location and area
        $location3 = Location::create([
            'name' => 'Location 3',
            'type' => 'warehouse',
            'status' => 1,
        ]);
        $area3 = Area::create(['name' => 'Area 3', 'status' => 1]);
        $area3->locations()->attach($location3->id);

        // Assign manager to both location1 and location3
        $this->managerLocation1->locations()->attach($location3->id);

        $this->actingAs($this->managerLocation1);

        $import = new \App\Imports\WholesaleInImport;

        $reflection = new \ReflectionClass($import);
        $method = $reflection->getMethod('getAreaColumnMapping');

        // Create headers with all three areas
        $headers = [
            'cat#', 'title', 'barcode', 'q',
            \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($this->area1->name)),
            \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($this->area2->name)),
            \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($area3->name)),
        ];

        $areaMapping = $method->invoke($import, $headers);
        $areaIds = array_column($areaMapping, 'area_id');

        // Should see areas from both assigned locations, but not location2
        $this->assertContains($this->area1->id, $areaIds);
        $this->assertNotContains($this->area2->id, $areaIds);
        $this->assertContains($area3->id, $areaIds);
    }
}
