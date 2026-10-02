<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    protected array $models = [
        // Customer::class => 20,
        // Label::class => 10,
        // Location::class => 80,
        // Format::class => 5,
        Supplier::class => 10,
        // Artist::class => 30,
        // Store::class => 5,
        // Record::class => 30,
        Sale::class => 30,
    ];

    public function run(): void
    {
        // First seed roles and users
        if (
            (Schema::hasTable('users') && DB::table('users')->count() === 0) &&
            (Schema::hasTable('roles') && DB::table('roles')->count() === 0)
        ) {
            $this->call([
                RolesPermissionsSeeder::class,
                UserSeeder::class,
            ]);
        } else {
            $this->command->warn('users and roles are not empty and have not been seeded.');
        }

        // Seed the reference data (areas, locations, catalog) and some fake customers

        if ((Schema::hasTable('areas') && DB::table('areas')->count() === 0) &&
           (Schema::hasTable('locations') && DB::table('locations')->count() === 0) &&
           (Schema::hasTable('customers') && DB::table('customers')->count() === 0) &&
           (Schema::hasTable('formats') && DB::table('formats')->count() === 0) &&
           (Schema::hasTable('artists') && DB::table('artists')->count() === 0) &&
           (Schema::hasTable('labels') && DB::table('labels')->count() === 0) &&
           (Schema::hasTable('records') && DB::table('records')->count() === 0)) {

            $this->call([
                AreaSeeder::class,
                LocationSeeder::class,
                CustomerSeeder::class,
                FormatSeeder::class,
                ArtistSeeder::class,
                LabelSeeder::class,
                RecordSeeder::class,
            ]);

        } else {
            $this->command->warn('Not working, some table not empty and have not been seeded.');
        }

        // Then seed other models using factories directly
        foreach ($this->models as $model => $count) {
            $tableName = (new $model)->getTable();
            if (Schema::hasTable($tableName) && $model::count() === 0) {
                $this->command->info('Seeding '.$tableName);
                $model::factory($count)->create();
            } else {
                $this->command->warn($tableName.' table is not empty and has not been seeded.');
            }
        }
    }
}
