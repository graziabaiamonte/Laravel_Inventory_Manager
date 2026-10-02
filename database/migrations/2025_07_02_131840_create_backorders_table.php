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
        Schema::create('backorders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_out_id')->constrained('wholesale_outs')->onDelete('cascade');
            $table->integer('status')->default(0); // 0 = pending, 1 = fulfilled, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backorders');
    }
};
