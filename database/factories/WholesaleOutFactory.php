<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Customer;
use App\Models\WholesaleOut;
use Illuminate\Database\Eloquent\Factories\Factory;

class WholesaleOutFactory extends Factory
{
    protected $model = WholesaleOut::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'area_id' => Area::factory(),
            'total_price' => $this->faker->numberBetween(1000, 50000), // bigInteger in cents
            'file' => null,
            'description' => $this->faker->optional()->sentence(),
            'doc_num' => $this->faker->unique()->numerify('WO####'),
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
