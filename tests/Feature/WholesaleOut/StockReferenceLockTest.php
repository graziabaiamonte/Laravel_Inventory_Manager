<?php

namespace Tests\Feature\WholesaleOut;

use App\Http\Controllers\WholesaleOutController;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Covers lockReferencedStocks(), which closes the gap between validating that a
 * records stock_id exists and inserting the row that references it.
 *
 * A stock row can be deleted inside that gap:
 * WholesaleOutRequest checks existence with Stock::find(), then the insert runs
 * later in the transaction. The lock makes a concurrent delete wait; this test
 * covers the other half, a row already gone by the time we lock.
 *
 * The lock itself needs two concurrent connections to observe, so it is not
 * asserted here.
 */
class StockReferenceLockTest extends WholesaleOutTestCase
{
    /** @param array<int,array<string,mixed>> $records */
    private function lockReferencedStocks(array &$records): void
    {
        $method = new ReflectionMethod(WholesaleOutController::class, 'lockReferencedStocks');

        // lockForUpdate() only means anything inside a transaction, which is how
        // both callers of saveWholesaleOutWithRecords() invoke this.
        DB::transaction(function () use ($method, &$records) {
            $method->invokeArgs(app(WholesaleOutController::class), [&$records]);
        });
    }

    public function test_a_live_stock_reference_is_kept(): void
    {
        $stock = Stock::where('record_id', $this->record1->id)->firstOrFail();

        $records = [['record_id' => $this->record1->id, 'stock_id' => $stock->id]];
        $this->lockReferencedStocks($records);

        $this->assertSame($stock->id, $records[0]['stock_id']);
        $this->assertEmpty(session()->get('importWarnings', []));
    }

    public function test_a_vanished_stock_reference_is_dropped_to_null_and_warned(): void
    {
        $stock = Stock::where('record_id', $this->record1->id)->firstOrFail();
        $goneId = $stock->id;
        $stock->delete();

        $records = [['record_id' => $this->record1->id, 'stock_id' => $goneId]];
        $this->lockReferencedStocks($records);

        // null is what the schema uses for a line with no stock behind it, and
        // is what the insert would otherwise have failed on with a 1452.
        $this->assertNull($records[0]['stock_id']);

        $warnings = session()->get('importWarnings', []);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString((string) $goneId, $warnings[0]);
    }

    public function test_only_the_vanished_reference_of_several_is_dropped(): void
    {
        $live = Stock::where('record_id', $this->record1->id)->firstOrFail();
        $doomed = Stock::where('record_id', $this->record2->id)->firstOrFail();
        $goneId = $doomed->id;
        $doomed->delete();

        $records = [
            ['record_id' => $this->record1->id, 'stock_id' => $live->id],
            ['record_id' => $this->record2->id, 'stock_id' => $goneId],
            // A backorder row references nothing and must be left alone.
            ['record_id' => $this->record2->id, 'stock_id' => null],
        ];
        $this->lockReferencedStocks($records);

        $this->assertSame($live->id, $records[0]['stock_id']);
        $this->assertNull($records[1]['stock_id']);
        $this->assertNull($records[2]['stock_id']);
        $this->assertCount(1, session()->get('importWarnings', []));
    }

    public function test_records_with_no_stock_reference_are_a_no_op(): void
    {
        $records = [['record_id' => $this->record1->id, 'stock_id' => null]];
        $this->lockReferencedStocks($records);

        $this->assertNull($records[0]['stock_id']);
        $this->assertEmpty(session()->get('importWarnings', []));
    }
}
