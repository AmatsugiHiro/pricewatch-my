<?php

namespace Database\Factories;

use App\Models\Premise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Premise>
 */
class PremiseFactory extends Factory
{
    protected $model = Premise::class;

    public function definition(): array
    {
        return [
            'premise_code' => fake()->unique()->numberBetween(1, 900_000),
            'premise' => mb_strtoupper(fake()->company()),
            'address' => fake()->address(),
            'premise_type' => fake()->randomElement([
                'Pasar Basah',
                'Kedai Runcit',
                'Pasar Raya / Supermarket',
                'Hypermarket',
                'Pasar Tani',
            ]),
            'state' => fake()->randomElement([
                'Selangor', 'Perak', 'Melaka', 'Johor', 'Pulau Pinang', 'Kedah',
            ]),
            'district' => fake()->city(),
        ];
    }

    public function withCode(int $code): static
    {
        return $this->state(fn (): array => ['premise_code' => $code]);
    }

    public function inState(string $state): static
    {
        return $this->state(fn (): array => ['state' => $state]);
    }

    /**
     * The source contains premises with no state recorded. The aggregator must
     * exclude them rather than inventing a bucket for them.
     */
    public function withoutState(): static
    {
        return $this->state(fn (): array => ['state' => null, 'district' => null]);
    }
}
