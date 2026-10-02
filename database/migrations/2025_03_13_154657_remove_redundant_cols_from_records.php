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
        Schema::table('records', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['location_id', 'supplier_id', 'quantity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->integer('quantity');
        });
    }
};
