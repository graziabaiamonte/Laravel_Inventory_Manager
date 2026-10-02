<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Fake customers only, never seed real personal data
        Customer::factory()->count(6)->create(['status' => 1]);
    }
}
