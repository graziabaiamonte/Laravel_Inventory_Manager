<?php

namespace Database\Factories;

use App\Enums\StatesEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => StatesEnum::Active->value,
            'created_at' => fake()->dateTimeBetween('-2 years'),
            'updated_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
