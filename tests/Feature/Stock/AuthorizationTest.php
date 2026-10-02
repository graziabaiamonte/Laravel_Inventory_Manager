<?php

namespace Tests\Feature\Stock;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting and reordering stocks is limited to the areas connected to the user
 * locations, and reordering is a PATCH rather than a GET.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Stock $ownStock;

    private Stock $otherOwnStock;

    private Stock $foreignStock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $ownArea = Area::factory()->create(['name' => 'Own Area']);
        $otherOwnArea = Area::factory()->create(['name' => 'Other Own Area']);
        $foreignArea = Area::factory()->create(['name' => 'Foreign Area']);

        $ownLocation = Location::factory()->create(['type' => LocationTypeEnum::WAREHOUSE, 'status' => 1]);
        $ownLocation->areas()->attach([$ownArea->id, $otherOwnArea->id]);
        $foreignLocation = Location::factory()->create(['type' => LocationTypeEnum::WAREHOUSE, 'status' => 1]);
        $foreignLocation->areas()->attach($foreignArea->id);

        $this->operator = User::factory()->create();
        $this->operator->assignRole(RolesEnum::Operator->value);
        $this->operator->locations()->attach($ownLocation->id);

        $record = Record::factory()->create([
            'format_id' => Format::factory()->create()->id,
            'label_id' => Label::factory()->create()->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);

        $this->ownStock = Stock::create(['record_id' => $record->id, 'area_id' => $ownArea->id, 'quantity' => 5]);
        $this->otherOwnStock = Stock::create(['record_id' => $record->id, 'area_id' => $otherOwnArea->id, 'quantity' => 5]);
        $this->foreignStock = Stock::create(['record_id' => $record->id, 'area_id' => $foreignArea->id, 'quantity' => 5]);
    }

    public function test_a_foreign_stock_cannot_be_deleted(): void
    {
        $this->actingAs($this->operator)
            ->delete(route('stock.destroy', $this->foreignStock))
            ->assertForbidden();

        $this->assertModelExists($this->foreignStock);
        $this->assertSame(5, (int) $this->foreignStock->fresh()->quantity);
    }

    public function test_an_own_stock_can_be_deleted(): void
    {
        $this->actingAs($this->operator)
            ->delete(route('stock.destroy', $this->ownStock))
            ->assertRedirect();

        $this->assertModelMissing($this->ownStock);
    }

    public function test_swap_is_not_reachable_with_get(): void
    {
        $this->actingAs($this->operator)
            ->get(route('stock.swap', ['dragging' => $this->ownStock->id, 'target' => $this->otherOwnStock->id]))
            ->assertStatus(405);
    }

    public function test_a_foreign_stock_cannot_be_swapped(): void
    {
        $this->actingAs($this->operator)
            ->patch(route('stock.swap', ['dragging' => $this->ownStock->id, 'target' => $this->foreignStock->id]))
            ->assertForbidden();
    }

    public function test_own_stocks_can_be_swapped(): void
    {
        $this->actingAs($this->operator)
            ->patch(route('stock.swap', ['dragging' => $this->ownStock->id, 'target' => $this->otherOwnStock->id]))
            ->assertRedirect();
    }
}
