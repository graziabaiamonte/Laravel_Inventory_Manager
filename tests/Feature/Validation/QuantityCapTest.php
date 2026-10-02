<?php

namespace Tests\Feature\Validation;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Http\Requests\BackorderRequest;
use App\Http\Requests\RecordRequest;
use App\Http\Requests\SaleRequest;
use App\Http\Requests\StockRequest;
use App\Http\Requests\WholesaleInRequest;
use App\Http\Requests\WholesaleOutRequest;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every quantity input is capped at Stock::MAX_QUANTITY.
 *
 * stocks.quantity is a signed integer, so a barcode typed or scanned into a
 * quantity field overflows the column and MySQL rejects the write with a 1264
 * after the surrounding work is already done. Six request classes carry the cap
 * and the rules were edited by hand, some converted from a piped string to an
 * array alongside existing closures, so the presence of each one is asserted
 * rather than assumed.
 */
class QuantityCapTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,array{class-string,string,string}> */
    public static function cappedRules(): array
    {
        return [
            'sale line' => [SaleRequest::class, 'store', 'records.*.quantity'],
            'backorder line' => [BackorderRequest::class, 'update', 'records.*.quantity'],
            'wholesale-out line' => [WholesaleOutRequest::class, 'store', 'records.*.quantity'],
            'wholesale-out area' => [WholesaleOutRequest::class, 'store', 'records.*.area_quantities.*.quantity'],
            'wholesale-in line' => [WholesaleInRequest::class, 'store', 'records.*.quantity'],
            'wholesale-in area' => [WholesaleInRequest::class, 'store', 'records.*.area_quantities.*.quantity'],
            'record stock' => [RecordRequest::class, 'update', 'stock.*.quantity'],
            'stock form' => [StockRequest::class, 'update', 'quantity'],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('cappedRules')]
    public function test_the_quantity_rule_carries_the_cap(string $class, string $action, string $key): void
    {
        $rules = $this->rulesFor($class, $action);

        $this->assertArrayHasKey($key, $rules, "{$class} no longer defines {$key}");

        $this->assertContains(
            'max:'.Stock::MAX_QUANTITY,
            $this->scalarRules($rules[$key]),
            "{$class} does not cap {$key}: a barcode in this field would overflow stocks.quantity"
        );
    }

    /**
     * The cap is worthless if it does not actually reject, so exercise one for
     * real through the sale endpoint, the most common entry point for this input.
     */
    public function test_a_barcode_in_a_sale_quantity_is_rejected(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(RolesEnum::Admin->value);

        $area = Area::factory()->create();
        $location = Location::factory()->create([
            'type' => LocationTypeEnum::STORE,
            'status' => 1,
            'default_area_id' => $area->id,
        ]);

        $record = Record::factory()->create([
            'format_id' => Format::factory()->create()->id,
            'label_id' => Label::factory()->create()->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);
        $stock = Stock::create([
            'record_id' => $record->id,
            'area_id' => $area->id,
            'quantity' => 50,
        ]);

        $response = $this->actingAs($user)->post(route('sale.store'), [
            'location_id' => $location->id,
            'type' => 0,
            'amount' => 10.00,
            'date' => '2026-07-01',
            'records' => [[
                'record_id' => $record->id,
                'stock_id' => $stock->id,
                // A 13-digit barcode, as scanned into the quantity field.
                'quantity' => 8055515237672,
                'discount' => 0,
                'vat' => 22,
                'price' => 10.00,
            ]],
        ]);

        $response->assertSessionHasErrors('records.0.quantity');
        $this->assertSame(50, $stock->fresh()->quantity, 'The stock must be untouched');
    }

    /**
     * Builds the rule set without going through HTTP. Closures inside the array
     * are not invoked here, but a few rules resolve the route while being built,
     * so a null resolver is supplied.
     *
     * @param  class-string  $class
     * @return array<string,mixed>
     */
    private function rulesFor(string $class, string $action): array
    {
        $request = $class::create('/', $action === 'store' ? 'POST' : 'PUT');
        $request->setContainer($this->app);
        $request->setRouteResolver(fn () => null);

        return $request->validateAction($action);
    }

    /**
     * @param  array<int,mixed>|string  $rule
     * @return array<int,string>
     */
    private function scalarRules(array|string $rule): array
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        return array_values(array_filter($rule, 'is_string'));
    }
}
