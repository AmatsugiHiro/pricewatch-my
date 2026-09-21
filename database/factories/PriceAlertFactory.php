<?php

namespace Database\Factories;

use App\Models\PriceAlert;
use App\Models\WatchItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceAlert>
 */
class PriceAlertFactory extends Factory
{
    protected $model = PriceAlert::class;

    public function definition(): array
    {
        $threshold = fake()->randomFloat(2, 5, 30);

        return [
            'watch_item_id' => WatchItem::factory(),
            'observed_on' => now()->toDateString(),
            'observed_price' => $threshold - 1,
            'threshold_price' => $threshold,
            'notified_at' => null,
        ];
    }
}
