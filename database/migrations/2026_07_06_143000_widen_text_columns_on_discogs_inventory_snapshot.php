<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discogs release descriptions (and seller locations) can exceed 255 chars,
     * which truncated/failed the inventory upsert at scale. Widen them to TEXT.
     */
    public function up(): void
    {
        Schema::table('discogs_inventory_snapshot', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
            $table->text('location')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('discogs_inventory_snapshot', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
            $table->string('location')->nullable()->change();
        });
    }
};
