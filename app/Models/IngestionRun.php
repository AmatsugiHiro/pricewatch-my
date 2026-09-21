<?php

namespace App\Models;

use Database\Factories\IngestionRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit record for a single ingestion attempt.
 *
 * Every run is recorded whether it succeeds or fails, which makes the pipeline
 * observable from the UI instead of only from the log file.
 */
class IngestionRun extends Model
{
    /** @use HasFactory<IngestionRunFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const DATASET_ITEMS = 'lookup_item';

    public const DATASET_PREMISES = 'lookup_premise';

    public const DATASET_PRICES = 'pricecatcher';

    protected $fillable = [
        'dataset',
        'period',
        'status',
        'rows_read',
        'rows_upserted',
        'rows_quarantined',
        'rows_malformed',
        'unknown_codes',
        'duration_ms',
        'peak_memory_bytes',
        'source_checksum',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rows_read' => 'integer',
            'rows_upserted' => 'integer',
            'rows_quarantined' => 'integer',
            'rows_malformed' => 'integer',
            'unknown_codes' => 'array',
            'duration_ms' => 'integer',
            'peak_memory_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_SKIPPED], true);
    }

    public function durationForHumans(): string
    {
        if ($this->duration_ms === null) {
            return '—';
        }

        return $this->duration_ms < 1000
            ? "{$this->duration_ms} ms"
            : number_format($this->duration_ms / 1000, 1).' s';
    }

    public function peakMemoryForHumans(): string
    {
        if ($this->peak_memory_bytes === null) {
            return '—';
        }

        return number_format($this->peak_memory_bytes / 1048576, 1).' MB';
    }

    /**
     * Rows ingested per second — the headline number for the performance write-up.
     */
    public function throughput(): ?float
    {
        if (! $this->duration_ms || $this->duration_ms <= 0) {
            return null;
        }

        return $this->rows_read / ($this->duration_ms / 1000);
    }
}
