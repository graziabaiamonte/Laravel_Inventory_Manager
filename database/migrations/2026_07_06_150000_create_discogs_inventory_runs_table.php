<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per inventory fetch run. Records whether the fetch fully covered
     * the seller inventory, so reconcile can refuse to act on an incomplete
     * snapshot (a missing page looks exactly like "no live listing").
     */
    public function up(): void
    {
        Schema::create('discogs_inventory_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id')->index();
            $table->string('status'); // Discogs status filter the fetch used
            $table->unsignedInteger('expected_items')->nullable();
            $table->unsignedInteger('expected_pages')->nullable();
            $table->unsignedInteger('pages_attempted')->default(0);
            $table->json('pages_failed')->nullable();
            $table->unsignedInteger('stored_listings')->default(0);
            $table->boolean('complete')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discogs_inventory_runs');
    }
};
