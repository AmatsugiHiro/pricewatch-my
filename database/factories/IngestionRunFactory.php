<?php

namespace Database\Factories;

use App\Models\IngestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestionRun>
 */
class IngestionRunFactory extends Factory
{
    protected $model = IngestionRun::class;

    public function definition(): array
    {
        $read = fake()->numberBetween(100_000, 2_000_000);
        $quarantined = (int) round($read * fake()->randomFloat(4, 0, 0.02));

        return [
            'dataset' => IngestionRun::DATASET_PRICES,
            'period' => fake()->date('Y-m'),
            'status' => IngestionRun::STATUS_COMPLETED,
            'rows_read' => $read,
            'rows_upserted' => $read - $quarantined,
            'rows_quarantined' => $quarantined,
            'rows_malformed' => 0,
            'unknown_codes' => null,
            'duration_ms' => fake()->numberBetween(1_000, 200_000),
            'peak_memory_bytes' => fake()->numberBetween(30, 60) * 1_048_576,
            'source_checksum' => hash('sha256', fake()->uuid()),
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ];
    }

    public function failed(string $error = 'Connection timed out'): static
    {
        return $this->state(fn (): array => [
            'status' => IngestionRun::STATUS_FAILED,
            'error' => $error,
            'rows_upserted' => 0,
        ]);
    }
}
