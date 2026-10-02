<?php

namespace Database\Factories;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'record_id' => \App\Models\Record::factory(),
            'area_id' => \App\Models\Area::factory(),
            'quantity' => $this->faker->numberBetween(10, 200),
        ];
    }
}
