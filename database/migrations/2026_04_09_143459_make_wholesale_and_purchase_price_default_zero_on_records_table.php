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
        // Convert existing NULL values to 0 before changing column constraints
        DB::table('records')->whereNull('wholesale_price')->update(['wholesale_price' => 0]);
        DB::table('records')->whereNull('purchase_price')->update(['purchase_price' => 0]);

        Schema::table('records', function (Blueprint $table) {
            $table->bigInteger('wholesale_price')->default(0)->change();
            $table->bigInteger('purchase_price')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->bigInteger('wholesale_price')->change();
            $table->bigInteger('purchase_price')->nullable()->change();
        });
    }
};
