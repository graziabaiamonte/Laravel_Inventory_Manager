<?php

namespace Tests\Feature\Sale;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecordOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $location;

    private Area $area;

    private Record $record1;

    private Record $record2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole(RolesEnum::Admin->value);

        $this->area = Area::factory()->create(['name' => 'Store Area']);
        $this->location = Location::factory()->create([
            'type' => LocationTypeEnum::STORE,
            'status' => 1,
            'default_area_id' => $this->area->id,
        ]);

        $format = Format::factory()->create();
        $label = Label::factory()->create();
        $artist = Artist::factory()->create();
        $this->record1 = Record::factory()->create(['format_id' => $format->id, 'label_id' => $label->id, 'artist_id' => $artist->id]);
        $this->record2 = Record::factory()->create(['format_id' => $format->id, 'label_id' => $label->id, 'artist_id' => $artist->id]);

        Stock::create(['record_id' => $this->record1->id, 'area_id' => $this->area->id, 'quantity' => 50]);
        Stock::create(['record_id' => $this->record2->id, 'area_id' => $this->area->id, 'quantity' => 50]);
    }

    private function stockId(Record $record): int
    {
        return Stock::where('record_id', $record->id)->where('area_id', $this->area->id)->first()->id;
    }

    private function lineItem(Record $record, ?int $id = null): array
    {
        $li = [
            'record_id' => $record->id,
            'stock_id' => $this->stockId($record),
            'quantity' => 1,
            'discount' => 0,
            'vat' => 22,
            'price' => 10.00,
        ];
        if ($id !== null) {
            $li['id'] = $id;
        }

        return $li;
    }

    public function test_sale_records_reload_in_add_order_not_id_order(): void
    {
        // Create the sale with record2 submitted first (most recently added), then record1.
        $this->actingAs($this->user)->post(route('sale.store'), [
            'location_id' => $this->location->id,
            'type' => 0,
            'amount' => 20.00,
            'date' => '2026-07-01',
            'records' => [
                $this->lineItem($this->record2),
                $this->lineItem($this->record1),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->first();

        $this->actingAs($this->user)->get(route('sale.edit', $sale))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sale/Edit')
                ->has('sale_records', 2)
                ->where('sale_records.0.record_id', $this->record2->id)
                ->where('sale_records.1.record_id', $this->record1->id)
            );

        $sr2 = $sale->saleRecords()->where('record_id', $this->record2->id)->first();
        $sr1 = $sale->saleRecords()->where('record_id', $this->record1->id)->first();

        // Add a brand-new record on top -> it gets the HIGHEST id yet must reload FIRST (position 0).
        $format = $this->record1->format_id;
        $record3 = Record::factory()->create(['format_id' => $format, 'label_id' => $this->record1->label_id, 'artist_id' => $this->record1->artist_id]);
        Stock::create(['record_id' => $record3->id, 'area_id' => $this->area->id, 'quantity' => 50]);

        $this->actingAs($this->user)->put(route('sale.update', $sale), [
            'location_id' => $this->location->id,
            'type' => 0,
            'amount' => 30.00,
            'date' => '2026-07-01',
            'records' => [
                $this->lineItem($record3),
                $this->lineItem($this->record2, $sr2->id),
                $this->lineItem($this->record1, $sr1->id),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $newSr = $sale->fresh()->saleRecords()->where('record_id', $record3->id)->first();
        $this->assertGreaterThan($sr1->id, $newSr->id, 'Sanity: newly added sale record has the highest id');

        $this->actingAs($this->user)->get(route('sale.edit', $sale))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sale/Edit')
                ->has('sale_records', 3)
                ->where('sale_records.0.record_id', $record3->id)
                ->where('sale_records.1.record_id', $this->record2->id)
                ->where('sale_records.2.record_id', $this->record1->id)
            );
    }
}
