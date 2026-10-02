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
        Schema::table('records_import_record_tmp', function (Blueprint $table) {
            $table->text('stocks_tmp')->nullable()->after('for_sale_on_discogs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('records_import_record_tmp', function (Blueprint $table) {
            $table->dropColumn('stocks_tmp');
        });
    }
};
