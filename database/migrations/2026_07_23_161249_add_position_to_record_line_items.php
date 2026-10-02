<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order in which records were added to a document (line items).
     * Sorted ASC on reload so the most recently added record shows first,
     * independent of the auto-increment id (which does not track add order).
     */
    private array $tables = [
        'wholesale_in_records',
        'wholesale_out_records',
        'sale_records',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Appended at the end (no ->after()) so MySQL can use ALGORITHM=INSTANT
                // on all 8.0.12+ versions, avoiding a table rebuild/lock on large tables.
                $table->unsignedInteger('position')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('position');
            });
        }
    }
};
