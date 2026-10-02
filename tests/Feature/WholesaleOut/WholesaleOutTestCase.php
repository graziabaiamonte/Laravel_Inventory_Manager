<?php

namespace Tests\Feature\WholesaleOut;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\Stock;
use App\Models\User;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WholesaleOutTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Customer $customer;

    protected Area $area1;

    protected Area $area2;

    protected Area $defaultArea;

    protected Record $record1;

    protected Record $record2;

    protected Format $format;

    protected Label $label;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        // Run the roles and permissions seeder first
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        // Create and authenticate an admin user
        $this->user = User::factory()->create();

        // Assign admin role (permissions should already exist from seeder)
        $this->user->assignRole(RolesEnum::Admin->value);

        Auth::login($this->user);

        // Create basic data that factories depend on
        $this->format = Format::factory()->create();
        $this->label = Label::factory()->create();
        $this->artist = Artist::factory()->create();

        // Create basic data
        $this->customer = Customer::factory()->create();
        $this->area1 = Area::factory()->create(['name' => 'Area 1']);
        $this->area2 = Area::factory()->create(['name' => 'Area 2']);
        $this->defaultArea = $this->area1;

        // Create warehouse locations with default areas (required for validation)
        Location::factory()->create([
            'type' => LocationTypeEnum::WAREHOUSE,
            'status' => 1,
            'default_area_id' => $this->area1->id,
        ]);
        Location::factory()->create([
            'type' => LocationTypeEnum::WAREHOUSE,
            'status' => 1,
            'default_area_id' => $this->area2->id,
        ]);

        // Create records
        $this->record1 = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);
        $this->record2 = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);

        // Create stock manually since StockFactory doesn't exist
        Stock::create([
            'record_id' => $this->record1->id,
            'area_id' => $this->area1->id,
            'quantity' => 100,
        ]);
        Stock::create([
            'record_id' => $this->record1->id,
            'area_id' => $this->area2->id,
            'quantity' => 50,
        ]);
        Stock::create([
            'record_id' => $this->record2->id,
            'area_id' => $this->area1->id,
            'quantity' => 75,
        ]);
    }

    protected function createActiveWholesaleOut(array $recordsData): WholesaleOut
    {
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->defaultArea->id,
            'status' => 0, // Initially inactive
        ]);

        foreach ($recordsData as $data) {
            WholesaleOutRecord::factory()->create(array_merge($data, [
                'wholesale_out_id' => $wholesaleOut->id,
            ]));
        }

        // Activate the wholesale out and allocate initial stock
        $wholesaleOut->status = 1;
        $wholesaleOut->save();

        // Manually perform initial stock allocation for testing
        // (In the real app, this would be done by the controller or other service)
        foreach ($wholesaleOut->records as $record) {
            // Create area assignment for the default area
            $record->wholesaleOutRecordsArea()->create([
                'area_id' => $this->defaultArea->id,
                'quantity' => $record->quantity,
            ]);

            // Decrement stock from the default area
            $stock = Stock::where('record_id', $record->record_id)
                ->where('area_id', $this->defaultArea->id)
                ->first();

            if ($stock && $stock->quantity >= $record->quantity) {
                $stock->decrement('quantity', $record->quantity);
            }
        }

        return $wholesaleOut->fresh();
    }

    protected function getRecordFromWholesaleOut(WholesaleOut $wo, Record $record): ?WholesaleOutRecord
    {
        return $wo->records()->where('record_id', $record->id)->first();
    }
}
