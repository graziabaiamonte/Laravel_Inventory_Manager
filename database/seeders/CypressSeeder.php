<?php

namespace Database\Seeders;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Backorder;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\RecordsImport;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WholesaleIn;
use App\Models\WholesaleOut;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Deterministic fixture for the Cypress end to end tests (cypress/e2e).
 *
 * The operator only has access to the "Own" locations, everything named
 * "Foreign" belongs to locations it can't see. Specs look records up by these
 * names, so keep them stable.
 */
class CypressSeeder extends Seeder
{
    public function run(): void
    {
        // Creates known credentials (admin@example.com / password), never on a real environment
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('CypressSeeder only runs in the local and testing environments.');
        }

        $this->call(RolesPermissionsSeeder::class);

        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);
        $admin->assignRole(RolesEnum::Admin->value);

        $operator = User::factory()->create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);
        $operator->assignRole(RolesEnum::Operator->value);

        $ownStoreArea = Area::factory()->create(['name' => 'Own Store Area', 'status' => 1]);
        $ownWarehouseArea = Area::factory()->create(['name' => 'Own Warehouse Area', 'status' => 1]);
        $ownBackArea = Area::factory()->create(['name' => 'Own Back Area', 'status' => 1]);
        $foreignStoreArea = Area::factory()->create(['name' => 'Foreign Store Area', 'status' => 1]);
        $foreignWarehouseArea = Area::factory()->create(['name' => 'Foreign Warehouse Area', 'status' => 1]);

        $ownStore = $this->location('Own Store', LocationTypeEnum::STORE, $ownStoreArea);
        $ownWarehouse = $this->location('Own Warehouse', LocationTypeEnum::WAREHOUSE, $ownWarehouseArea, [$ownBackArea]);
        $ownWarehouse->update(['default_wholesaleout_location' => 1]);
        $foreignStore = $this->location('Foreign Store', LocationTypeEnum::STORE, $foreignStoreArea);
        $this->location('Foreign Warehouse', LocationTypeEnum::WAREHOUSE, $foreignWarehouseArea);
        Location::factory()->create(['name' => 'Empty Location', 'type' => LocationTypeEnum::STORE, 'status' => 1]);

        $operator->locations()->attach([$ownStore->id, $ownWarehouse->id]);

        $format = Format::factory()->create(['name' => 'LP', 'status' => 1]);
        $label = Label::factory()->create(['name' => 'Cypress Label', 'status' => 1]);
        $artist = Artist::factory()->create(['name' => 'Cypress Artist', 'status' => 1]);

        $record = Record::factory()->create([
            'title' => 'Cypress Record',
            'barcode' => '8012345678901',
            'cat_number' => 'CY-001',
            'format_id' => $format->id,
            'label_id' => $label->id,
            'artist_id' => $artist->id,
        ]);

        // Two stocks in areas the operator can see, so they can be reordered, plus a foreign one
        Stock::create(['record_id' => $record->id, 'area_id' => $ownWarehouseArea->id, 'quantity' => 10]);
        Stock::create(['record_id' => $record->id, 'area_id' => $ownBackArea->id, 'quantity' => 5]);
        Stock::create(['record_id' => $record->id, 'area_id' => $ownStoreArea->id, 'quantity' => 20]);
        Stock::create(['record_id' => $record->id, 'area_id' => $foreignWarehouseArea->id, 'quantity' => 7]);

        $customer = Customer::factory()->create(['name' => 'Cypress', 'last_name' => 'Customer', 'status' => 1]);
        $supplier = Supplier::factory()->create(['name' => 'Cypress Supplier', 'status' => 1]);

        $ownWholesaleOut = WholesaleOut::factory()->create(['doc_num' => 'WO-OWN', 'customer_id' => $customer->id, 'area_id' => $ownWarehouseArea->id]);
        Backorder::factory()->pending()->create(['wholesale_out_id' => $ownWholesaleOut->id]);
        WholesaleOut::factory()->create(['doc_num' => 'WO-FOREIGN', 'customer_id' => $customer->id, 'area_id' => $foreignWarehouseArea->id]);
        WholesaleIn::factory()->create(['doc_num' => 'WI-OWN', 'supplier_id' => $supplier->id, 'area_id' => $ownWarehouseArea->id]);
        WholesaleIn::factory()->create(['doc_num' => 'WI-FOREIGN', 'supplier_id' => $supplier->id, 'area_id' => $foreignWarehouseArea->id]);

        RecordsImport::create(['draft' => true]);

        Sale::factory()->count(2)->create(['user_id' => $admin->id, 'location_id' => $ownStore->id]);
        Sale::factory()->count(3)->create(['user_id' => $admin->id, 'location_id' => $foreignStore->id]);
    }

    private function location(string $name, string $type, Area $defaultArea, array $extraAreas = []): Location
    {
        $location = Location::factory()->create([
            'name' => $name,
            'type' => $type,
            'status' => 1,
            'default_area_id' => $defaultArea->id,
        ]);
        $location->areas()->attach(array_merge([$defaultArea->id], array_map(fn (Area $area) => $area->id, $extraAreas)));

        return $location;
    }
}
