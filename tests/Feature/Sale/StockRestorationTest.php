<?php

namespace Tests\Feature\Sale;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing a line from a sale gives the stock back without deleting the row.
 *
 * The old code deleted the stock whenever the restored quantity did not come
 * back above zero, which is how an oversold record lost its stock row: with
 * sale_records.stock_id being ON DELETE CASCADE that silently took the sale
 * line too, and where a wholesale-out line also referenced the row it raised a
 * 1451 instead.
 */
class StockRestorationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $location;

    private Area $area;

    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(RolesEnum::Admin->value);

        $this->area = Area::factory()->create();
        $this->location = Location::factory()->create([
            'type' => LocationTypeEnum::STORE,
            'status' => 1,
            'default_area_id' => $this->area->id,
        ]);

        $this->record = Record::factory()->create([
            'format_id' => Format::factory()->create()->id,
            'label_id' => Label::factory()->create()->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);
    }

    private function stock(int $quantity = 50): Stock
    {
        return Stock::create([
            'record_id' => $this->record->id,
            'area_id' => $this->area->id,
            'quantity' => $quantity,
        ]);
    }

    /** Creates a sale holding one line of $quantity against $stock. */
    private function saleWithOneLine(Stock $stock, int $quantity): Sale
    {
        $this->actingAs($this->user)->post(route('sale.store'), [
            'location_id' => $this->location->id,
            'type' => 0,
            'amount' => 10.00,
            'date' => '2026-07-01',
            'records' => [[
                'record_id' => $this->record->id,
                'stock_id' => $stock->id,
                'quantity' => $quantity,
                'discount' => 0,
                'vat' => 22,
                'price' => 10.00,
            ]],
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    /** Re-submits the sale with no lines at all, removing the existing one. */
    private function removeAllLines(Sale $sale): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->put(route('sale.update', $sale), [
            'location_id' => $this->location->id,
            'type' => 0,
            'amount' => 0,
            'date' => '2026-07-01',
            'records' => [],
        ]);
    }

    public function test_removing_a_line_gives_the_quantity_back(): void
    {
        $stock = $this->stock(50);
        $sale = $this->saleWithOneLine($stock, 2);

        $this->assertSame(48, $stock->fresh()->quantity, 'Sanity: the sale consumed the stock');

        $this->removeAllLines($sale)->assertSessionHasNoErrors();

        $this->assertSame(50, $stock->fresh()->quantity);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id]);
        $this->assertSame(0, $sale->fresh()->saleRecords()->count());
    }

    public function test_an_oversold_stock_keeps_its_row_when_the_restore_stays_negative(): void
    {
        $stock = $this->stock(50);
        $sale = $this->saleWithOneLine($stock, 3);

        // An oversold record: the restore will land on -2, which the old code
        // treated as a reason to delete the row.
        $stock->update(['quantity' => -5]);

        $this->removeAllLines($sale)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => -2]);
    }

    public function test_removing_a_line_whose_stock_a_wholesale_out_references_does_not_error(): void
    {
        $stock = $this->stock(50);
        $sale = $this->saleWithOneLine($stock, 3);
        $stock->update(['quantity' => -5]);

        $line = WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => WholesaleOut::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
            ])->id,
            'record_id' => $this->record->id,
            'stock_id' => $stock->id,
        ]);

        // This is the exact shape that used to raise the 1451.
        $this->removeAllLines($sale)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => -2]);
        $this->assertDatabaseHas('wholesale_out_records', ['id' => $line->id, 'stock_id' => $stock->id]);
    }
}
