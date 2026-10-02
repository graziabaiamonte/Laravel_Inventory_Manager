<?php

namespace Database\Seeders;

use App\Models\Format;
use Illuminate\Database\Seeder;

class FormatSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $format_names = [
            '10"', '12"', '2CD', '2LP', '2x7"', '3CD', '3LP', '4LP', '4x7"', '7', '7"', '7""',
            'All Media', 'Blu-ray', 'BOOK', 'BOX LP', 'Box Set', 'Cassette', 'CD', 'CDD', 'CDr',
            'CDV', 'DCD', 'DLP', 'DVD', 'DVDr', 'Flexi-disc', 'Hybrid', 'Laserdisc', 'Lathe Cut',
            'LP', 'LP 180 gram', 'LP and 7"', 'LP COLOR', 'LP+7"', 'LP+CD', 'LPPIC', 'LPX2', 'MC',
            'MLP', 'PIC DISC', 'SACD', 'Shellac', 'TAPE', 'VHS', 'Vinyl',
        ];

        foreach ($format_names as $name) {
            Format::factory()->create([
                'name' => $name,
                'status' => 1,
            ]);
        }
    }
}
