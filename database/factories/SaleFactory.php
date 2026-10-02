<?php

namespace Database\Factories;

use App\Enums\LocationTypeEnum;
use App\Enums\SaleTypeEnum;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::inRandomOrder()->first()->id,
            'location_id' => Location::where('type', LocationTypeEnum::STORE)->inRandomOrder()->first()->id,
            'remote_customer_id' => fake()->optional()->numberBetween(1000, 9999),
            'remote_customer_name' => fake()->optional()->name(),
            'type' => fake()->randomElement(SaleTypeEnum::getValues()),
            'amount' => fake()->numberBetween(1000, 100000), // Between 10 and 1000 in cents
            'date' => fake()->dateTimeBetween('-1 year'),
            'description' => fake()->optional()->sentence(),
            'created_at' => fake()->dateTimeBetween('-2 years'),
            'updated_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
