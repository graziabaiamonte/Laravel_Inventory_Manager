<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize records.release_id: historically some creation paths defaulted a missing Discogs
 * Release ID to the integer 0 instead of leaving the nullable column NULL. 0 is not a valid
 * Discogs release ID (real IDs start at 1), so it unambiguously means "none". This converts those
 * 0 values to NULL so "no Release ID" is represented consistently across the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('records')->where('release_id', 0)->update(['release_id' => null]);
    }

    public function down(): void
    {
        // Irreversible: the original rows stored 0 as a stand-in for "no Release ID", which is now
        // represented by NULL. We cannot tell a normalized row from one that was always NULL, so
        // there is nothing meaningful to restore.
    }
};
