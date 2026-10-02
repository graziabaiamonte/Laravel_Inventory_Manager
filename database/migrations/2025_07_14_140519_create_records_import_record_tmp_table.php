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
        Schema::create('records_import_record_tmp', function (Blueprint $table) {
            $table->id();
            $table->string('barcode');
            $table->string('cat_number');
            $table->unsignedBigInteger('release_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->bigInteger('retail_price');
            $table->bigInteger('wholesale_price');
            $table->bigInteger('purchase_price')->nullable();
            $table->tinyInteger('disk_status')->default(0);
            $table->tinyInteger('cover_status')->default(0);
            $table->text('description')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('format_id')->nullable()->constrained('formats')->onDelete('set null');
            $table->foreignId('label_id')->nullable()->constrained('labels')->onDelete('set null');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
            $table->foreignId('artist_id')->nullable()->constrained('artists')->onDelete('set null');
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->unsignedBigInteger('discogs_id')->nullable();
            $table->foreignId('records_import_id')->nullable()->constrained('records_imports')->onDelete('cascade');
            $table->foreignId('record_id')->nullable()->constrained('records')->onDelete('cascade');
            $table->tinyInteger('for_sale_on_discogs')->default(0);
            $table->tinyInteger('soft_delete')->default(0);
            $table->tinyInteger('delete')->default(0);
            $table->tinyInteger('d_delete')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('records_import_record_tmp');
    }
};
