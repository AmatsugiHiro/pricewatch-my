<?php

namespace App\Services\Ingestion;

use App\Models\IngestionRun;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps a unit of ingestion work in an audited IngestionRun row.
 *
 * Keeping this separate from the importers means timing, memory sampling and
 * failure recording are written once and behave identically for every dataset —
 * and an importer can be tested without caring how runs are recorded.
 */
final class IngestionRunRecorder
{
    /**
     * @param  Closure(IngestionRun): IngestionStats  $work
     */
    public function record(string $dataset, ?string $period, Closure $work): IngestionRun
    {
        $run = IngestionRun::create([
            'dataset' => $dataset,
            'period' => $period,
            'status' => IngestionRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $startedAt = hrtime(true);

        // Reset the high-water mark so the figure we store describes this run only,
        // not whatever the process did beforehand.
        memory_reset_peak_usage();

        try {
            $stats = $work($run);
        } catch (Throwable $exception) {
            $run->update([
                'status' => IngestionRun::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 2000),
                'duration_ms' => $this->elapsedMs($startedAt),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        $run->update([
            'status' => $stats->skipped
                ? IngestionRun::STATUS_SKIPPED
                : IngestionRun::STATUS_COMPLETED,
            'rows_read' => $stats->rowsRead,
            'rows_upserted' => $stats->rowsUpserted,
            'rows_quarantined' => $stats->rowsQuarantined,
            'rows_malformed' => $stats->rowsMalformed,
            'unknown_codes' => $stats->unknownCodesPayload(),
            'source_checksum' => $stats->checksum,
            'duration_ms' => $this->elapsedMs($startedAt),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'finished_at' => now(),
        ]);

        return $run->refresh();
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
