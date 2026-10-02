<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational snapshot of the Discogs seller inventory.
     *
     * This is a rebuildable cache, not domain data: it is repopulated on every
     * fetch run so reconciliation (and later de-listing) can work against a
     * local copy without re-spending the Discogs API rate-limit budget.
     */
    public function up(): void
    {
        Schema::create('discogs_inventory_snapshot', function (Blueprint $table) {
            $table->unsignedBigInteger('listing_id')->primary();
            $table->unsignedBigInteger('release_id')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->string('condition')->nullable();
            $table->string('sleeve_condition')->nullable();
            $table->decimal('price_value', 12, 2)->nullable();
            $table->string('price_currency', 8)->nullable();
            $table->string('location')->nullable();
            $table->string('description')->nullable(); // Discogs "Artist - Title (...)" for the human report
            $table->json('raw')->nullable(); // full payload, insurance against re-fetching for fields we did not model
            $table->uuid('run_id')->index(); // groups rows fetched in the same run, and carries the snapshot age
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discogs_inventory_snapshot');
    }
};
