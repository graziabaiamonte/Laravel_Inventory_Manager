<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, clean up any existing duplicate stocks before adding the unique constraint
        // This handles legacy data that may have duplicates
        $duplicates = DB::table('stocks')
            ->select('record_id', 'area_id', DB::raw('COUNT(*) as count'))
            ->groupBy('record_id', 'area_id')
            ->having('count', '>', 1)
            ->get();

        echo "Found {$duplicates->count()} duplicate stock groups to consolidate...\n";

        foreach ($duplicates as $dup) {
            // Get all duplicate stocks for this record+area combination
            $stocks = \App\Models\Stock::where('record_id', $dup->record_id)
                ->where('area_id', $dup->area_id)
                ->orderBy('id')
                ->get();

            // Sum all quantities to preserve total stock count
            $totalQty = $stocks->sum('quantity');

            // Keep the first (oldest) stock record and update its quantity
            $firstStock = $stocks->first();
            $firstStock->update(['quantity' => $totalQty]);

            // Get IDs of duplicate stocks to merge
            $stockIdsToMerge = $stocks->skip(1)->pluck('id')->toArray();

            if (count($stockIdsToMerge) > 0) {
                // Update all foreign key references to point to the consolidated stock
                DB::table('wholesale_out_records')
                    ->whereIn('stock_id', $stockIdsToMerge)
                    ->update(['stock_id' => $firstStock->id]);

                DB::table('sale_records')
                    ->whereIn('stock_id', $stockIdsToMerge)
                    ->update(['stock_id' => $firstStock->id]);

                // Now safe to delete the duplicate stocks
                foreach ($stockIdsToMerge as $sid) {
                    \App\Models\Stock::find($sid)->delete();
                }
            }
        }

        echo "Consolidated {$duplicates->count()} duplicate stock groups.\n";

        // Now add the unique constraint to prevent future duplicates
        Schema::table('stocks', function (Blueprint $table) {
            $table->unique(['record_id', 'area_id'], 'stocks_record_area_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropUnique('stocks_record_area_unique');
        });
    }
};
