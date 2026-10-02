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
        Schema::create('wholesale_out_label_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_out_id')->constrained('wholesale_outs')->onDelete('cascade');
            $table->foreignId('label_id')->constrained('labels')->onDelete('cascade');
            $table->integer('discount')->default(0); // discount percentage (0-100)
            $table->timestamps();

            // Ensure unique combination of wholesale_out_id and label_id
            $table->unique(['wholesale_out_id', 'label_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wholesale_out_label_discounts');
    }
};
