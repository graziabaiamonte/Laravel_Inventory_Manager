<?php

namespace Database\Factories;

use App\Enums\StatesEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

class AreaFactory extends Factory
{
    public function definition(): array
    {
        // fake()->locale('it_IT'); // Force Italian locale

        return [
            'name' => fake()->streetName().' - '.fake()->city(),
            'status' => StatesEnum::Active->value,
            'created_at' => fake()->dateTimeBetween('-2 years'),
            'updated_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
