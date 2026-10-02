<?php

namespace Database\Factories;

use App\Models\Backorder;
use App\Models\WholesaleOut;
use Illuminate\Database\Eloquent\Factories\Factory;

class BackorderFactory extends Factory
{
    protected $model = Backorder::class;

    public function definition(): array
    {
        return [
            'wholesale_out_id' => WholesaleOut::factory(),
            'status' => $this->faker->randomElement([0, 1]),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 1,
        ]);
    }
}
