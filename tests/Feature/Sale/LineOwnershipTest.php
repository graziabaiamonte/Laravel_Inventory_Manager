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
use App\Models\SaleRecord;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sale update can only touch its own lines, and a line can only draw from a
 * stock of its own record in an area the user can access.
 */
class LineOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    private Area $area;

    private Record $record;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(RolesEnum::Admin->value);

        $this->area = Area::factory()->create(['name' => 'Store Area']);
        $this->location = Location::factory()->create([
            'type' => LocationTypeEnum::STORE,
            'status' => 1,
            'default_area_id' => $this->area->id,
        ]);
        $this->location->areas()->attach($this->area->id);

        $this->record = $this->makeRecord();
        $this->stock = Stock::create(['record_id' => $this->record->id, 'area_id' => $this->area->id, 'quantity' => 50]);
    }

    private function makeRecord(): Record
    {
        return Record::factory()->create([
            'format_id' => Format::factory()->create()->id,
            'label_id' => Label::factory()->create()->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);
    }

    private function payload(array $line): array
    {
        return [
            'location_id' => $this->location->id,
            'type' => 0,
            'amount' => 10.00,
            'date' => '2026-07-01',
            'records' => [array_merge([
                'record_id' => $this->record->id,
                'stock_id' => $this->stock->id,
                'quantity' => 1,
                'discount' => 0,
                'vat' => 22,
                'price' => 10.00,
            ], $line)],
        ];
    }

    private function createSale(): Sale
    {
        $this->actingAs($this->admin)->post(route('sale.store'), $this->payload([]))
            ->assertRedirect()->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }

    public function test_an_update_cannot_rewrite_a_line_of_another_sale(): void
    {
        $other = $this->createSale();
        $otherLine = $other->saleRecords()->first();
        $sale = $this->createSale();

        $this->actingAs($this->admin)
            ->put(route('sale.update', $sale), $this->payload(['id' => $otherLine->id, 'quantity' => 5]))
            ->assertSessionHasErrors('records.0.id');

        $this->assertSame(1, (int) SaleRecord::find($otherLine->id)->quantity);
    }

    public function test_a_new_sale_cannot_carry_an_existing_line_id(): void
    {
        $line = $this->createSale()->saleRecords()->first();

        $this->actingAs($this->admin)
            ->post(route('sale.store'), $this->payload(['id' => $line->id]))
            ->assertSessionHasErrors('records.0.id');
    }

    public function test_a_line_cannot_draw_from_the_stock_of_another_record(): void
    {
        $otherRecord = $this->makeRecord();
        $otherStock = Stock::create(['record_id' => $otherRecord->id, 'area_id' => $this->area->id, 'quantity' => 50]);

        $this->actingAs($this->admin)
            ->post(route('sale.store'), $this->payload(['stock_id' => $otherStock->id]))
            ->assertSessionHasErrors('records.0.stock_id');

        $this->assertSame(50, (int) $otherStock->fresh()->quantity);
    }

    public function test_a_line_cannot_draw_from_a_stock_outside_the_user_locations(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole(RolesEnum::Operator->value);
        $operator->locations()->attach($this->location->id);

        $foreignArea = Area::factory()->create(['name' => 'Foreign Area']);
        $foreignLocation = Location::factory()->create(['type' => LocationTypeEnum::STORE, 'status' => 1]);
        $foreignLocation->areas()->attach($foreignArea->id);
        $foreignStock = Stock::create(['record_id' => $this->record->id, 'area_id' => $foreignArea->id, 'quantity' => 50]);

        $this->actingAs($operator)
            ->post(route('sale.store'), $this->payload(['stock_id' => $foreignStock->id]))
            ->assertSessionHasErrors('records.0.stock_id');

        $this->assertSame(50, (int) $foreignStock->fresh()->quantity);
    }

    public function test_an_unchanged_line_keeps_a_stock_outside_the_user_locations(): void
    {
        [$operator, $foreignStock] = $this->operatorAndForeignStock();

        // Sold by an admin from the stock of another shop, as it often happens
        $this->actingAs($this->admin)->post(route('sale.store'), $this->payload(['stock_id' => $foreignStock->id]))
            ->assertSessionHasNoErrors();
        $sale = Sale::latest('id')->first();
        $line = $sale->saleRecords()->first();

        $this->actingAs($operator)
            ->put(route('sale.update', $sale), $this->payload(['id' => $line->id, 'stock_id' => $foreignStock->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_an_existing_line_cannot_switch_to_a_stock_outside_the_user_locations(): void
    {
        [$operator, $foreignStock] = $this->operatorAndForeignStock();

        $sale = $this->createSale();
        $line = $sale->saleRecords()->first();

        $this->actingAs($operator)
            ->put(route('sale.update', $sale), $this->payload(['id' => $line->id, 'stock_id' => $foreignStock->id]))
            ->assertSessionHasErrors('records.0.stock_id');
    }

    /** @return array{User, Stock} */
    private function operatorAndForeignStock(): array
    {
        $operator = User::factory()->create();
        $operator->assignRole(RolesEnum::Operator->value);
        $operator->locations()->attach($this->location->id);

        $foreignArea = Area::factory()->create(['name' => 'Other Shop Area']);
        $foreignLocation = Location::factory()->create(['type' => LocationTypeEnum::STORE, 'status' => 1]);
        $foreignLocation->areas()->attach($foreignArea->id);

        return [$operator, Stock::create(['record_id' => $this->record->id, 'area_id' => $foreignArea->id, 'quantity' => 50])];
    }

    public function test_a_line_without_stock_id_still_uses_the_default_area(): void
    {
        $this->actingAs($this->admin)
            ->post(route('sale.store'), $this->payload(['stock_id' => null]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(49, (int) $this->stock->fresh()->quantity);
    }
}
