<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('barcode')->nullable()->change();
            $table->string('cat_number')->nullable()->change();
        });

        Schema::table('records_import_record_tmp', function (Blueprint $table) {
            $table->string('barcode')->nullable()->change();
            $table->string('cat_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->string('barcode')->nullable(false)->change();
            $table->string('cat_number')->nullable(false)->change();
        });

        Schema::table('records_import_record_tmp', function (Blueprint $table) {
            $table->string('barcode')->nullable(false)->change();
            $table->string('cat_number')->nullable(false)->change();
        });
    }
};
