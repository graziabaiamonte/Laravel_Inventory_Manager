<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
            $table->integer('remote_customer_id')->nullable();
            $table->string('remote_customer_name')->nullable();
            $table->string('discogs_order_id')->nullable();
            $table->integer('type');
            $table->bigInteger('amount');
            $table->date('date');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('discogs_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
