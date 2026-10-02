<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Supplier;
use App\Models\WholesaleIn;
use Illuminate\Database\Eloquent\Factories\Factory;

class WholesaleInFactory extends Factory
{
    protected $model = WholesaleIn::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'area_id' => Area::factory(),
            'total_price' => $this->faker->numberBetween(1000, 50000),
            'file' => null,
            'description' => $this->faker->optional()->sentence(),
            'doc_num' => $this->faker->unique()->numerify('WI####'),
            'status' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 1,
        ]);
    }
}
