<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        // Names are upper-cased to match how data.gov.my publishes them, so tests
        // exercise the same display-formatting path as production data.
        return [
            'item_code' => fake()->unique()->numberBetween(1, 900_000),
            'item' => mb_strtoupper(fake()->words(3, true)),
            'unit' => fake()->randomElement(['1kg', '500g', '1 biji', '1 ikat', '340g']),
            'item_group' => fake()->randomElement(['BARANGAN SEGAR', 'BARANGAN KERING', 'MINUMAN']),
            'item_category' => fake()->randomElement(['AYAM', 'IKAN', 'SAYUR', 'BERAS', 'TELUR']),
        ];
    }

    public function withCode(int $code): static
    {
        return $this->state(fn (): array => ['item_code' => $code]);
    }
}
