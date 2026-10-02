<?php

namespace Tests\Feature\WholesaleIn;

use App\Models\Area;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholesaleInTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Supplier $supplier;

    protected Area $area1;

    protected Area $area2;

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

        // Create admin user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        // Create supplier
        $this->supplier = Supplier::factory()->create();

        // Create areas
        $this->area1 = Area::factory()->create(['name' => 'Test Area 1']);
        $this->area2 = Area::factory()->create(['name' => 'Test Area 2']);

        // Create format, label, artist
        $this->format = Format::factory()->create();
        $this->label = Label::factory()->create();
        $this->artist = Artist::factory()->create();

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

        // Create initial stock for testing
        Stock::factory()->create([
            'record_id' => $this->record1->id,
            'area_id' => $this->area1->id,
            'quantity' => 100,
        ]);

        Stock::factory()->create([
            'record_id' => $this->record2->id,
            'area_id' => $this->area1->id,
            'quantity' => 75,
        ]);
    }
}
