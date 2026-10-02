<?php

namespace Tests\Feature\Stock;

use App\Enums\LocationTypeEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\Sale;
use App\Models\SaleRecord;
use App\Models\Stock;
use App\Models\User;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use App\Services\External\DiscogsListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the rule that a stock row is never removed while a document line
 * still points at it.
 *
 * wholesale_out_records.stock_id is ON DELETE RESTRICT, so the old behaviour
 * raised a 1451 and failed the request; sale_records.stock_id is ON DELETE
 * CASCADE, so it silently destroyed the sale line instead. Both are covered
 * here because the two failure modes look nothing alike at runtime.
 */
class DeletionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Record $record;

    protected Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        // SaleFactory resolves a STORE location in its definition(), so one has
        // to exist before any Sale is made.
        Location::factory()->create(['type' => LocationTypeEnum::STORE]);

        $this->area = Area::factory()->create();
        $this->record = Record::factory()->create([
            'format_id' => Format::factory()->create()->id,
            'label_id' => Label::factory()->create()->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);
    }

    private function stock(int $quantity = 5, ?Area $area = null): Stock
    {
        return Stock::factory()->create([
            'record_id' => $this->record->id,
            'area_id' => ($area ?? $this->area)->id,
            'quantity' => $quantity,
        ]);
    }

    private function wholesaleOutLineFor(Stock $stock): WholesaleOutRecord
    {
        $wholesaleOut = WholesaleOut::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);

        return WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $this->record->id,
            'stock_id' => $stock->id,
        ]);
    }

    public function test_stock_referenced_by_a_wholesale_out_line_is_emptied_not_deleted(): void
    {
        $stock = $this->stock(5);
        $line = $this->wholesaleOutLineFor($stock);

        $this->assertFalse($stock->deleteOrEmpty(), 'A referenced row must not report itself deleted');

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 0]);
        $this->assertDatabaseHas('wholesale_out_records', ['id' => $line->id, 'stock_id' => $stock->id]);
    }

    public function test_stock_referenced_by_a_sale_line_is_emptied_and_the_sale_line_survives(): void
    {
        $stock = $this->stock(5);

        $sale = Sale::factory()->create(['user_id' => $this->user->id]);
        $saleRecord = SaleRecord::create([
            'sale_id' => $sale->id,
            'record_id' => $this->record->id,
            'stock_id' => $stock->id,
            'quantity' => 2,
            'price' => 1000,
            'discount' => 0,
            'total_price' => 2000,
            'vat' => 22,
        ]);

        $this->assertFalse($stock->deleteOrEmpty());

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 0]);
        // The FK cascades, so the old delete took this row with it silently.
        $this->assertDatabaseHas('sale_records', ['id' => $saleRecord->id]);
    }

    public function test_unreferenced_stock_is_still_deleted(): void
    {
        $stock = $this->stock(5);

        $this->assertTrue($stock->deleteOrEmpty());

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    /**
     * Deleting a stock fired the `deleted` model event, which is what tells the
     * Discogs listing service to re-evaluate the record. Emptying a row instead
     * has to keep that notification, or a record could stay listed with nothing
     * behind it. The `updated` hook covers it, because Eloquent syncs the
     * original attributes after firing `updated`, so quantity is still dirty.
     */
    public function test_emptying_a_referenced_stock_still_notifies_the_listing_service(): void
    {
        $spy = $this->spyOnListingService();

        $stock = $this->stock(5);
        $this->wholesaleOutLineFor($stock);

        $stock->deleteOrEmpty();

        $this->assertSame([$this->record->id], $spy->notified);
    }

    public function test_deleting_an_unreferenced_stock_still_notifies_the_listing_service(): void
    {
        $spy = $this->spyOnListingService();

        $this->stock(5)->deleteOrEmpty();

        $this->assertSame([$this->record->id], $spy->notified);
    }

    public function test_an_already_empty_stock_does_not_notify_because_nothing_changed(): void
    {
        $spy = $this->spyOnListingService();

        $stock = $this->stock(0);
        $this->wholesaleOutLineFor($stock);

        $stock->deleteOrEmpty();

        $this->assertSame([], $spy->notified, 'A row going 0 -> 0 changes no availability');
    }

    private function spyOnListingService(): object
    {
        $spy = new class
        {
            /** @var array<int,int> */
            public array $notified = [];

            public function handleStockChanged($record): void
            {
                $this->notified[] = $record->id;
            }
        };

        $this->app->instance(DiscogsListingService::class, $spy);

        return $spy;
    }

    public function test_destroying_a_referenced_stock_from_the_ui_does_not_error(): void
    {
        $stock = $this->stock(5);
        $line = $this->wholesaleOutLineFor($stock);

        $response = $this->actingAs($this->user)
            ->from(route('record.edit', $this->record->id))
            ->delete(route('stock.destroy', $stock->id));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 0]);
        $this->assertDatabaseHas('wholesale_out_records', ['id' => $line->id, 'stock_id' => $stock->id]);
    }

    public function test_removing_an_area_on_record_update_empties_a_referenced_stock(): void
    {
        $keep = $this->stock(3);
        $dropped = $this->stock(4, Area::factory()->create());
        $line = $this->wholesaleOutLineFor($dropped);

        $response = $this->actingAs($this->user)->put(route('record.update', $this->record->id), [
            'rr_uid' => $this->record->rr_uid,
            'artist_id' => $this->record->artist_id,
            'label_id' => $this->record->label_id,
            'format_id' => $this->record->format_id,
            'type' => $this->record->type,
            'title' => $this->record->title,
            'disk_status' => $this->record->disk_status,
            'cover_status' => $this->record->cover_status,
            'for_sale_on_discogs' => 0,
            // Only the kept area is submitted, so the other one is "deleted".
            'stock' => [
                ['area_id' => $keep->area_id, 'quantity' => 3],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $dropped->id, 'quantity' => 0]);
        $this->assertDatabaseHas('wholesale_out_records', ['id' => $line->id, 'stock_id' => $dropped->id]);
    }

    public function test_a_barcode_sized_quantity_is_rejected_before_it_overflows_the_column(): void
    {
        $stock = $this->stock(5);

        $response = $this->actingAs($this->user)->put(route('stock.update', $stock->id), [
            'record_id' => $this->record->id,
            'area_id' => $this->area->id,
            // A 13-digit barcode scanned into the quantity field overflows the
            // column (MySQL 1264).
            'quantity' => 8055515237672,
        ]);

        $response->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 5]);
    }
}
