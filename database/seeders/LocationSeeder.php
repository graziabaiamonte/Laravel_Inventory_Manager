<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $location_names = [
            'Magazzino',
            'Monti',
            'N.T.R. New Technical Removal Co S.r.l.',
            'Pigneto',
            'Trastevere',
        ];

        foreach ($location_names as $name) {
            $location = Location::factory()->create([
                'name' => $name,
                'type' => 'store',
                'status' => 1,
            ]);

            switch ($name) {
                case 'Magazzino':
                    $location->type = 'warehouse';
                    $location->default_wholesaleout_location = 1;
                    $location->save();
                    $location->areas()->sync([6, 7]);
                    break;
                case 'Monti':
                    $location->areas()->sync([8, 9]);
                    break;
                case 'N.T.R. New Technical Removal Co S.r.l.':
                    $location->areas()->sync([10]);
                    break;
                case 'Pigneto':
                    $location->areas()->sync([11, 12]);
                    break;
                case 'Trastevere':
                    break;
            }

            $defaultArea = Area::where('name', $name)->first();
            if ($defaultArea) {
                $location->areas()->save($defaultArea);
                $location->defaultArea()->associate($defaultArea);
                $location->save();
            }

        }

    }
}
