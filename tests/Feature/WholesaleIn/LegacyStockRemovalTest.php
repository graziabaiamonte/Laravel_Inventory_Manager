<?php

namespace Tests\Feature\WholesaleIn;

use App\Http\Controllers\WholesaleInController;
use App\Models\Stock;
use ReflectionMethod;

/**
 * removeStockLegacyMethod() is the last-resort path used when a WholesaleIn is
 * deactivated with no area information at all. It used to delete any stock row
 * whose quantity did not stay above zero, which is the same defect the sibling
 * removeStockFromArea() already carried a comment warning against.
 */
class LegacyStockRemovalTest extends WholesaleInTestCase
{
    private function removeLegacy(int $recordId, int $quantity): void
    {
        $method = new ReflectionMethod(WholesaleInController::class, 'removeStockLegacyMethod');
        $method->invoke(app(WholesaleInController::class), $recordId, $quantity);
    }

    public function test_stock_rows_are_kept_when_the_quantity_reaches_or_passes_zero(): void
    {
        // The base case already stocks record1 in area1; give it a second area
        // so one row stays positive and the other is driven under zero.
        $keepsPositive = Stock::where('record_id', $this->record1->id)
            ->where('area_id', $this->area1->id)
            ->firstOrFail();
        $keepsPositive->update(['quantity' => 5]);

        $goesNegative = Stock::create([
            'record_id' => $this->record1->id,
            'area_id' => $this->area2->id,
            'quantity' => 2,
        ]);

        $this->removeLegacy($this->record1->id, 3);

        $this->assertDatabaseHas('stocks', ['id' => $keepsPositive->id, 'quantity' => 2]);

        // The old code deleted this row rather than writing -1, taking any sale
        // line with it and raising a 1451 if a wholesale-out line referenced it.
        $this->assertDatabaseHas('stocks', ['id' => $goesNegative->id, 'quantity' => -1]);
    }

    public function test_a_stock_landing_exactly_on_zero_is_kept(): void
    {
        $stock = Stock::where('record_id', $this->record2->id)
            ->where('area_id', $this->area1->id)
            ->firstOrFail();
        $stock->update(['quantity' => 4]);

        $this->removeLegacy($this->record2->id, 4);

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 0]);
    }
}
