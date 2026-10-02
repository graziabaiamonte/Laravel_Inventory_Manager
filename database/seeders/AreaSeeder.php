<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $posti_names = [
            'Magazzino',
            'Monti',
            'N.T.R. New Technical Removal Co S.r.l.',
            'Pigneto',
            'Trastevere',
            'Area 1',
            'Area 10',
            'Hard Rock',
            'Pop',
            'In Sospeso',
            'Prog',
            'Ska',
        ];

        foreach ($posti_names as $name) {
            Area::factory()->create([
                'name' => $name,
                'status' => 1,
            ]);
        }

    }
}
