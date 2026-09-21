<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Premise;
use App\Models\PriceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceRecord>
 */
class PriceRecordFactory extends Factory
{
    protected $model = PriceRecord::class;

    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'premise_code' => Premise::factory(),
            'item_code' => Item::factory(),
            'price' => fake()->randomFloat(2, 1, 60),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['date' => $date]);
    }

    public function priced(float $price): static
    {
        return $this->state(fn (): array => ['price' => $price]);
    }
}
