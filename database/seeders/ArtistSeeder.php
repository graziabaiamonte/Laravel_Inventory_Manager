<?php

namespace Database\Seeders;

use App\Models\Artist;
use Illuminate\Database\Seeder;

class ArtistSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $artists = [
            'Circle Jerks',
            'Suuns',
            'Saxon',
            'Icp Tentet',
            'Ping Pong',
            'Agincourt',
            'Patrick Rondat',
            'The Pilgrim',
            'Mudhoney',
            'School Of Fish',
            'Fridge',
            'Sven Wunder',
            'Outskirts Of Infinity',
            'Shabaka And The Ancestors',
            'Sonny Rollins',
        ];

        foreach ($artists as $name) {
            Artist::factory()->create([
                'name' => $name,
                'status' => 1,
            ]);
        }
    }
}
