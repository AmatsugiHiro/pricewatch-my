<?php

namespace App\Console\Commands;

use App\Models\DailyItemStatePrice;
use App\Models\PriceRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Trims the raw observation table to a retention window.
 *
 * The fact table is ~90 bytes a row all-in and grows by ~1.7 million rows a
 * month, so a year of it is roughly 1.8 GB — more than a free managed database
 * will hold. Almost none of it is read: every chart, trend and state comparison
 * is served from daily_item_state_prices, and the one query that does touch raw
 * records (cheapest premises) only ever looks at a single day.
 *
 * So the rollup is kept forever and the raw rows behind it are aged out. The
 * history is not lost — it is reproducible at any time from data.gov.my with
 * `pricewatch:sync --month=…`, which is the whole point of an idempotent import.
 *
 * The guard below is the important part. `pricewatch:sync --months=2` rebuilds
 * the rollup for the last two months from raw records; pruning inside that
 * window would leave the next scheduled run rebuilding those rollups from rows
 * that are no longer there, quietly blanking a month of charts.
 */
class PruneObservations extends Command
{
    protected $signature = 'pricewatch:prune
        {--keep-days=70 : Retain raw observations from this many days before the latest}
        {--chunk=50000 : Rows to delete per statement}
        {--dry-run : Report what would be deleted without deleting it}
        {--force : Prune inside the re-aggregation window anyway}';

    protected $description = 'Delete raw price observations older than the retention window';

    /**
     * Two calendar months, plus a few days of slack. Anything shorter than this
     * can be reached by `pricewatch:sync --months=2`.
     */
    private const REAGGREGATION_WINDOW_DAYS = 70;

    public function handle(): int
    {
        $keepDays = max(1, (int) $this->option('keep-days'));

        if ($keepDays < self::REAGGREGATION_WINDOW_DAYS && ! $this->option('force')) {
            $this->components->error(sprintf(
                'Refusing to keep only %d days. The scheduled sync re-aggregates the last two '
                .'months, so pruning inside %d days would rebuild those rollups from rows that '
                .'no longer exist. Pass --force if you are certain.',
                $keepDays,
                self::REAGGREGATION_WINDOW_DAYS,
            ));

            return self::FAILURE;
        }

        $latest = DB::table('price_records')->max('date');

        if ($latest === null) {
            $this->components->info('No observations stored; nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::parse($latest)->subDays($keepDays)->toDateString();

        $doomed = DB::table('price_records')->where('date', '<', $cutoff)->count();

        $this->components->twoColumnDetail('Latest observation', Carbon::parse($latest)->toDateString());
        $this->components->twoColumnDetail('Retention window', $keepDays.' days');
        $this->components->twoColumnDetail('Deleting rows before', $cutoff);
        $this->components->twoColumnDetail('Rows to delete', number_format($doomed));

        if ($doomed === 0) {
            $this->components->info('Nothing older than the retention window.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        $rollupBefore = DailyItemStatePrice::query()->count();

        $chunk = max(1000, (int) $this->option('chunk'));
        $deleted = 0;
        $started = hrtime(true);

        $bar = $this->output->createProgressBar($doomed);
        $bar->start();

        // Deleted in chunks rather than one statement: a single DELETE of millions
        // of rows builds an enormous undo log, holds locks for the duration, and on
        // a small managed instance is a good way to be killed mid-statement.
        do {
            $removed = DB::table('price_records')
                ->where('date', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $removed;
            $bar->advance($removed);
        } while ($removed > 0);

        $bar->finish();
        $this->newLine(2);

        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);
        $rollupAfter = DailyItemStatePrice::query()->count();

        $this->components->twoColumnDetail('Rows deleted', number_format($deleted));
        $this->components->twoColumnDetail('Duration', number_format($elapsed / 1000, 1).' s');
        $this->components->twoColumnDetail('Observations remaining', number_format(PriceRecord::query()->count()));
        $this->components->twoColumnDetail(
            'Rollup rows (must be unchanged)',
            $rollupBefore === $rollupAfter
                ? '<fg=green>'.number_format($rollupAfter).'</>'
                : '<fg=red>'.number_format($rollupBefore).' -> '.number_format($rollupAfter).'</>'
        );

        if ($rollupBefore !== $rollupAfter) {
            $this->components->error('The rollup changed during pruning. That should be impossible.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
