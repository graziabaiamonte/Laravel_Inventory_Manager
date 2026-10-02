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
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->string('barcode');
            $table->string('cat_number');
            $table->unsignedBigInteger('release_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->bigInteger('retail_price');
            $table->bigInteger('wholesale_price');
            $table->bigInteger('purchase_price')->nullable();
            $table->integer('quantity');
            $table->tinyInteger('disk_status')->default(0);
            $table->tinyInteger('cover_status')->default(0);
            $table->text('description')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('format_id')->nullable()->constrained('formats')->onDelete('set null');
            $table->foreignId('label_id')->nullable()->constrained('labels')->onDelete('set null');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
            $table->foreignId('artist_id')->nullable()->constrained('artists')->onDelete('set null');
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
