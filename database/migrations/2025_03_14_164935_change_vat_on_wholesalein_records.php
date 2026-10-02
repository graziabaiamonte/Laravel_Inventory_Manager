<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_in_records', function (Blueprint $table) {
            $table->integer('vat')->change();
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_in_records', function (Blueprint $table) {
            $table->string('vat')->change();
        });
    }
};
