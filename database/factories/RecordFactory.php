<?php

namespace Database\Factories;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\ForSaleOnDiscogsStatusEnum;
use App\Enums\RecordTypeEnum;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'barcode' => fake()->ean13(),
            'cat_number' => fake()->bothify('???-####'),
            'release_id' => fake()->numberBetween(1000000, 9999999),
            'type' => fake()->randomElement(RecordTypeEnum::getValues()),
            'title' => fake()->words(3, true),
            'retail_price' => fake()->numberBetween(1000, 10000),
            'wholesale_price' => fake()->numberBetween(500, 8000),
            'purchase_price' => fake()->numberBetween(100, 5000),
            'disk_status' => fake()->randomElement(DiskStatusEnum::getValues()),
            'cover_status' => fake()->randomElement(CoverStatusEnum::getValues()),
            // 'for_sale_on_discogs' => fake()->randomElement(ForSaleOnDiscogsStatusEnum::getValues()),
            'for_sale_on_discogs' => 0,
            'description' => fake()->paragraph(),
            'comments' => fake()->optional()->paragraph(),
            'format_id' => Format::inRandomOrder()->first()->id,
            'label_id' => Label::inRandomOrder()->first()->id,
            'artist_id' => Artist::inRandomOrder()->first()->id,
            'created_at' => fake()->dateTimeBetween('-2 years'),
            'updated_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
