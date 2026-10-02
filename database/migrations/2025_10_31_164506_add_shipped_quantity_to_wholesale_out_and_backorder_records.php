<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add shipped_quantity to wholesale_out_records
        Schema::table('wholesale_out_records', function (Blueprint $table) {
            $table->integer('shipped_quantity')->default(0)->after('quantity');
        });

        // Add shipped_quantity to backorder_records
        Schema::table('backorder_records', function (Blueprint $table) {
            $table->integer('shipped_quantity')->default(0)->after('quantity');
        });

        // On-the-fly backfilling will happen when WholesaleOuts are updated
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wholesale_out_records', function (Blueprint $table) {
            $table->dropColumn('shipped_quantity');
        });

        Schema::table('backorder_records', function (Blueprint $table) {
            $table->dropColumn('shipped_quantity');
        });
    }
};
