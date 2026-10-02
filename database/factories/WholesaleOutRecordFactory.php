<?php

namespace Database\Factories;

use App\Models\Record;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class WholesaleOutRecordFactory extends Factory
{
    protected $model = WholesaleOutRecord::class;

    public function definition(): array
    {
        $unitPrice = $this->faker->numberBetween(500, 5000); // 5.00 to 50.00 EUR in cents
        $quantity = $this->faker->numberBetween(1, 100);
        $discount = $this->faker->numberBetween(0, 25);
        $totalPrice = ($unitPrice * $quantity) * (100 - $discount) / 100;

        return [
            'wholesale_out_id' => WholesaleOut::factory(),
            'record_id' => Record::factory(),
            'stock_id' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'total_price' => (int) $totalPrice,
            'vat' => $this->faker->numberBetween(0, 22), // VAT percentage
        ];
    }
}
