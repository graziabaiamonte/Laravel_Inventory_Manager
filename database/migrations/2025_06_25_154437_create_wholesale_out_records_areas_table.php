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
        Schema::create('wholesale_out_records_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_out_records_id')->constrained('wholesale_out_records')->onDelete('cascade');
            $table->foreignId('area_id')->constrained('areas')->onDelete('restrict');
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wholesale_out_records_areas');
    }
};
