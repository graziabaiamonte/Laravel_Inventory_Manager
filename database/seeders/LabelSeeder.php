<?php

namespace Database\Seeders;

use App\Models\Label;
use Illuminate\Database\Seeder;

class LabelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $labels = [
            'Century Media Records',
            'Joyful Noise Recordings',
            'Emi',
            'Our Swimmer',
            'Cinedelic Records',
            'Trading Places',
            'Food For Thought Records',
            'Heavy Psych Sounds',
            'Sub Pop',
            'Capitol Records',
            'Domino',
            'Piano Piano',
            'Trading Places',
            'Brownswood Recordings',
            'Milestone',
        ];

        foreach ($labels as $name) {
            Label::factory()->create([
                'name' => $name,
                'status' => 1,
            ]);
        }
    }
}
