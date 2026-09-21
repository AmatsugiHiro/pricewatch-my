<?php

namespace Database\Factories;

use App\Models\DailyItemStatePrice;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyItemStatePrice>
 */
class DailyItemStatePriceFactory extends Factory
{
    protected $model = DailyItemStatePrice::class;

    public function definition(): array
    {
        $min = fake()->randomFloat(2, 1, 20);
        $max = $min + fake()->randomFloat(2, 0, 10);

        return [
            'date' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'item_code' => Item::factory(),
            'state' => fake()->randomElement(['Selangor', 'Perak', 'Johor', 'Melaka']),
            'min_price' => $min,
            'max_price' => $max,
            'avg_price' => round(($min + $max) / 2, 4),
            'sample_count' => fake()->numberBetween(5, 500),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['date' => $date]);
    }

    public function inState(string $state): static
    {
        return $this->state(fn (): array => ['state' => $state]);
    }

    public function averaging(float $average, int $samples = 100): static
    {
        return $this->state(fn (): array => [
            'avg_price' => $average,
            'min_price' => $average,
            'max_price' => $average,
            'sample_count' => $samples,
        ]);
    }
}
