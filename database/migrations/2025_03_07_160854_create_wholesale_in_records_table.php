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
        Schema::create('wholesale_in_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_in_id')->constrained()->onDelete('cascade');
            // $table->foreignId('record_id')->constrained()->onDelete('restrict');
            $table->foreignId('record_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->bigInteger('unit_price');
            $table->integer('discount')->default(0);
            $table->bigInteger('total_price');
            $table->string('vat');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wholesale_in_records');
    }
};
