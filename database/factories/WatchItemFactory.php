<?php

namespace Database\Factories;

use App\Enums\WatchDirection;
use App\Models\Item;
use App\Models\User;
use App\Models\WatchItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchItem>
 */
class WatchItemFactory extends Factory
{
    protected $model = WatchItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'item_code' => Item::factory(),
            'state' => 'Selangor',
            'threshold_price' => fake()->randomFloat(2, 5, 30),
            'direction' => WatchDirection::Below,
            'last_notified_at' => null,
        ];
    }

    public function national(): static
    {
        return $this->state(fn (): array => ['state' => null]);
    }

    public function watchingFor(WatchDirection $direction, float $threshold): static
    {
        return $this->state(fn (): array => [
            'direction' => $direction,
            'threshold_price' => $threshold,
        ]);
    }
}
