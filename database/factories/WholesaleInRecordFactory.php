<?php

namespace Database\Factories;

use App\Models\Record;
use App\Models\WholesaleIn;
use App\Models\WholesaleInRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class WholesaleInRecordFactory extends Factory
{
    protected $model = WholesaleInRecord::class;

    public function definition(): array
    {
        $unitPrice = $this->faker->numberBetween(500, 5000); // 5.00 to 50.00 EUR in cents
        $quantity = $this->faker->numberBetween(1, 100);
        $discount = $this->faker->numberBetween(0, 25);
        $totalPrice = ($unitPrice * $quantity) * (100 - $discount) / 100;

        return [
            'wholesale_in_id' => WholesaleIn::factory(),
            'record_id' => Record::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice / 100, // Convert to decimal
            'discount' => $discount,
            'total_price' => $totalPrice / 100, // Convert to decimal
            'vat' => $this->faker->numberBetween(0, 22), // VAT percentage
        ];
    }
}
