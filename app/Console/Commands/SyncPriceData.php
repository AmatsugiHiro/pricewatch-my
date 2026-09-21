<?php

namespace App\Console\Commands;

use App\Models\DailyItemStatePrice;
use App\Models\IngestionRun;
use App\Models\PriceRecord;
use App\Services\Aggregation\DailyPriceAggregator;
use App\Services\Alerts\WatchEvaluator;
use App\Services\Ingestion\LookupImporter;
use App\Services\Ingestion\PriceCatcherImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class SyncPriceData extends Command
{
    protected $signature = 'pricewatch:sync
        {--month=* : Specific period(s) to ingest, formatted YYYY-MM}
        {--months=1 : Ingest the most recent N months instead of naming them}
        {--lookups-only : Refresh items and premises, then stop}
        {--skip-lookups : Assume items and premises are already current}
        {--skip-aggregate : Load raw observations without rebuilding the daily rollup}
        {--skip-alerts : Do not evaluate watchlists after ingesting}
        {--force : Re-download and re-ingest even when the source is unchanged}';

    protected $description = 'Download and ingest KPDN PriceCatcher open data from data.gov.my';

    public function handle(
        LookupImporter $lookups,
        PriceCatcherImporter $prices,
        DailyPriceAggregator $aggregator,
    ): int {
        $force = (bool) $this->option('force');

        if (! $this->option('skip-lookups')) {
            if (! $this->syncLookups($lookups, $force)) {
                return self::FAILURE;
            }
        }

        if ($this->option('lookups-only')) {
            return self::SUCCESS;
        }

        $periods = $this->resolvePeriods();

        if ($periods === []) {
            $this->components->error('No periods to ingest.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($periods as $period) {
            if (! $this->syncPeriod($prices, $aggregator, $period, $force)) {
                $failed++;
            }
        }

        // Alerts run here rather than as a separate scheduled entry so that the
        // ordering is guaranteed: watches are always judged against a rollup that
        // has just been rebuilt, never a stale one.
        if (! $this->option('skip-alerts') && ! $this->option('skip-aggregate')) {
            $this->newLine();
            $this->components->info('Evaluating watchlists');

            $result = $this->app->make(WatchEvaluator::class)->evaluate();

            $this->components->twoColumnDetail('Watches evaluated', number_format($result->evaluated));
            $this->components->twoColumnDetail('Thresholds breached', number_format($result->breached));
            $this->components->twoColumnDetail('Notifications sent', number_format($result->notified));
        }

        $this->newLine();
        $this->summarise();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function syncLookups(LookupImporter $lookups, bool $force): bool
    {
        foreach (['items' => 'importItems', 'premises' => 'importPremises'] as $label => $method) {
            try {
                $run = $lookups->{$method}($force);
            } catch (Throwable $e) {
                $this->components->error("Failed to import {$label}: {$e->getMessage()}");

                return false;
            }

            $this->components->twoColumnDetail(
                "Lookup: {$label}",
                $run->status === IngestionRun::STATUS_SKIPPED
                    ? '<fg=gray>unchanged</>'
                    : sprintf(
                        '<fg=green>%s rows</> in %s',
                        number_format($run->rows_upserted),
                        $run->durationForHumans(),
                    )
            );
        }

        return true;
    }

    private function syncPeriod(
        PriceCatcherImporter $prices,
        DailyPriceAggregator $aggregator,
        string $period,
        bool $force,
    ): bool {
        $this->newLine();
        $this->components->info("Ingesting {$period}");

        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% rows read | %elapsed:6s% | %message%');
        $bar->setMessage('starting');
        $bar->start();

        try {
            $run = $prices->import($period, $force, function (int $read, int $upserted, int $quarantined) use ($bar): void {
                $bar->setProgress($read);
                $bar->setMessage(sprintf(
                    '%s upserted, %s quarantined',
                    number_format($upserted),
                    number_format($quarantined),
                ));
            });
        } catch (Throwable $e) {
            $bar->finish();
            $this->newLine(2);
            $this->components->error("{$period}: {$e->getMessage()}");

            return false;
        }

        $bar->finish();
        $this->newLine(2);

        if ($run->status === IngestionRun::STATUS_SKIPPED) {
            $this->components->twoColumnDetail($period, '<fg=gray>source unchanged, skipped</>');

            return true;
        }

        $this->components->twoColumnDetail('Rows read', number_format($run->rows_read));
        $this->components->twoColumnDetail('Rows upserted', number_format($run->rows_upserted));
        $this->components->twoColumnDetail(
            'Rows quarantined',
            number_format($run->rows_quarantined).sprintf(' (%.4f%%)', $run->rows_read > 0
                ? $run->rows_quarantined / $run->rows_read * 100
                : 0)
        );
        $this->components->twoColumnDetail('Duration', $run->durationForHumans());
        $this->components->twoColumnDetail('Peak memory', $run->peakMemoryForHumans());
        $this->components->twoColumnDetail(
            'Throughput',
            $run->throughput() !== null ? number_format($run->throughput()).' rows/s' : '—'
        );

        if (! $this->option('skip-aggregate')) {
            $started = hrtime(true);
            $rows = $aggregator->rebuildPeriod($period);
            $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);

            $this->components->twoColumnDetail(
                'Rollup rebuilt',
                sprintf('%s rows in %s ms', number_format($rows), number_format($elapsed))
            );
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function resolvePeriods(): array
    {
        /** @var array<int, string> $explicit */
        $explicit = (array) $this->option('month');

        if ($explicit !== []) {
            $invalid = array_filter($explicit, fn (string $p): bool => preg_match('/^\d{4}-\d{2}$/', $p) !== 1);

            if ($invalid !== []) {
                $this->components->error('Invalid period(s): '.implode(', ', $invalid).'. Expected YYYY-MM.');

                return [];
            }

            return array_values(array_unique($explicit));
        }

        $count = max(1, (int) $this->option('months'));
        $cursor = Carbon::now()->startOfMonth();
        $periods = [];

        for ($i = 0; $i < $count; $i++) {
            $periods[] = $cursor->format('Y-m');
            $cursor->subMonth();
        }

        // Oldest first, so a partially-complete current month is ingested last.
        return array_reverse($periods);
    }

    private function summarise(): void
    {
        $this->components->twoColumnDetail(
            '<fg=white;options=bold>Observations stored</>',
            '<fg=white;options=bold>'.number_format(PriceRecord::query()->count()).'</>'
        );
        $this->components->twoColumnDetail(
            '<fg=white;options=bold>Rollup rows</>',
            '<fg=white;options=bold>'.number_format(DailyItemStatePrice::query()->count()).'</>'
        );
    }
}
