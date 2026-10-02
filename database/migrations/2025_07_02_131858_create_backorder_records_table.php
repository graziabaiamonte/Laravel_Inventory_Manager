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
        Schema::create('backorder_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backorder_id')->constrained('backorders')->onDelete('cascade');
            $table->foreignId('wholesale_out_record_id')->constrained('wholesale_out_records')->onDelete('cascade');
            $table->integer('quantity')->unsigned();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backorder_records');
    }
};
