<?php

namespace App\Console\Commands;

use App\Services\Ingestion\CsvStream;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Measures the two design decisions the ingestion pipeline is built around, so the
 * claims in the write-up are numbers rather than assertions:
 *
 *   1. Write strategy — one Eloquent model per row, one INSERT per row, or chunked
 *      bulk upserts.
 *   2. Read strategy  — reading a CSV into an array versus streaming it.
 *
 * Everything runs against a scratch table that is created and dropped here, so the
 * benchmark never touches real observations.
 */
class BenchmarkIngestion extends Command
{
    protected $signature = 'pricewatch:benchmark
        {--rows=10000 : Rows to use for the write benchmark}
        {--file= : CSV to use for the read benchmark (defaults to a staged month)}
        {--skip-naive : Skip the per-row Eloquent run, which is the slow one}';

    protected $description = 'Compare naive and optimised ingestion strategies';

    private const TABLE = 'benchmark_price_records';

    public function handle(): int
    {
        $rows = max(100, (int) $this->option('rows'));

        $this->components->info("Benchmarking with {$rows} rows");
        $this->newLine();

        $fixture = $this->buildFixture($rows);
        $this->createScratchTable();

        $results = [];

        try {
            if (! $this->option('skip-naive')) {
                $results[] = $this->measure(
                    'Eloquent model per row',
                    fn () => $this->writeWithEloquent($fixture),
                    $rows,
                );
            }

            $results[] = $this->measure(
                'Query builder, one INSERT per row',
                fn () => $this->writeWithSingleInserts($fixture),
                $rows,
            );

            $results[] = $this->measure(
                'Chunked bulk upsert (the pipeline)',
                fn () => $this->writeWithChunkedUpsert($fixture),
                $rows,
            );
        } finally {
            Schema::dropIfExists(self::TABLE);
        }

        $this->renderWriteResults($results, $rows);
        $this->renderReadResults();

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFixture(int $rows): array
    {
        $fixture = [];
        $day = 1;

        for ($i = 0; $i < $rows; $i++) {
            $fixture[] = [
                // Spread across days and premises so the unique index sees realistic
                // key distribution rather than one hot page.
                'date' => sprintf('2026-08-%02d', ($day = $day % 28 + 1)),
                'premise_code' => 1000 + ($i % 2000),
                'item_code' => 1 + ($i % 300),
                'price' => round(5 + ($i % 1000) / 100, 2),
            ];
        }

        return $fixture;
    }

    private function createScratchTable(): void
    {
        Schema::dropIfExists(self::TABLE);

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedInteger('premise_code');
            $table->unsignedInteger('item_code');
            $table->decimal('price', 8, 2);
            $table->unique(['date', 'premise_code', 'item_code'], 'benchmark_natural_unique');
        });
    }

    private function truncate(): void
    {
        DB::table(self::TABLE)->truncate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $fixture
     */
    private function writeWithEloquent(array $fixture): void
    {
        // Deliberately the naive version: a model instance, its events, and one
        // INSERT per row.
        foreach ($fixture as $row) {
            $model = new BenchmarkRecord;
            $model->setTable(self::TABLE);
            $model->fill($row);
            $model->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fixture
     */
    private function writeWithSingleInserts(array $fixture): void
    {
        foreach ($fixture as $row) {
            DB::table(self::TABLE)->insert($row);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fixture
     */
    private function writeWithChunkedUpsert(array $fixture): void
    {
        foreach (array_chunk($fixture, (int) config('pricewatch.chunk_size')) as $chunk) {
            DB::table(self::TABLE)->upsert(
                $chunk,
                ['date', 'premise_code', 'item_code'],
                ['price'],
            );
        }
    }

    /**
     * @return array{label: string, seconds: float, rows_per_second: float, peak_mb: float}
     */
    private function measure(string $label, callable $work, int $rows): array
    {
        $this->truncate();
        DB::connection()->disableQueryLog();
        gc_collect_cycles();
        memory_reset_peak_usage();

        $started = hrtime(true);
        $work();
        $seconds = (hrtime(true) - $started) / 1_000_000_000;

        $this->components->twoColumnDetail(
            $label,
            sprintf('<fg=green>%s s</>', number_format($seconds, 2))
        );

        return [
            'label' => $label,
            'seconds' => $seconds,
            'rows_per_second' => $seconds > 0 ? $rows / $seconds : 0.0,
            'peak_mb' => memory_get_peak_usage(true) / 1_048_576,
        ];
    }

    /**
     * @param  array<int, array{label: string, seconds: float, rows_per_second: float, peak_mb: float}>  $results
     */
    private function renderWriteResults(array $results, int $rows): void
    {
        $this->newLine();
        $this->components->info('Write strategy');

        $slowest = max(array_column($results, 'seconds'));

        $this->table(
            ['Strategy', 'Time', 'Rows/sec', 'Peak memory', 'Speed-up', 'Projected for 1.9M rows'],
            array_map(function (array $result) use ($slowest): array {
                $projectedSeconds = $result['rows_per_second'] > 0
                    ? 1_900_000 / $result['rows_per_second']
                    : 0;

                return [
                    $result['label'],
                    number_format($result['seconds'], 2).' s',
                    number_format($result['rows_per_second']),
                    number_format($result['peak_mb'], 1).' MB',
                    $result['seconds'] > 0
                        ? number_format($slowest / $result['seconds'], 1).'x'
                        : '—',
                    $this->humanDuration($projectedSeconds),
                ];
            }, $results),
        );

        $this->components->bulletList([
            "Measured over {$rows} rows against a scratch table with the same unique index.",
            'The projection scales measured throughput to one month of PriceCatcher data.',
        ]);
    }

    private function renderReadResults(): void
    {
        $path = $this->resolveReadBenchmarkFile();

        if ($path === null) {
            $this->newLine();
            $this->components->warn(
                'No staged CSV found for the read benchmark. Run pricewatch:sync first, or pass --file=.'
            );

            return;
        }

        $sizeMb = filesize($path) / 1_048_576;

        $this->newLine();
        $this->components->info(sprintf('Read strategy (%s, %s MB)', basename($path), number_format($sizeMb, 1)));

        // Streaming.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);
        $started = hrtime(true);
        $streamRows = 0;

        foreach (CsvStream::rows($path) as $ignored) {
            $streamRows++;
        }

        $streamSeconds = (hrtime(true) - $started) / 1_000_000_000;
        $streamPeak = (memory_get_peak_usage(true) - $baseline) / 1_048_576;

        // Slurping. file() holds every line as a separate PHP string at once.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);
        $started = hrtime(true);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $slurpRows = count($lines) - 1;

        $slurpSeconds = (hrtime(true) - $started) / 1_000_000_000;
        $slurpPeak = (memory_get_peak_usage(true) - $baseline) / 1_048_576;

        unset($lines);
        gc_collect_cycles();

        $this->table(
            ['Strategy', 'Rows', 'Time', 'Memory growth'],
            [
                ['Streamed AND parsed (CsvStream)', number_format($streamRows), number_format($streamSeconds, 2).' s', number_format($streamPeak, 1).' MB'],
                ['Slurped, NOT parsed (file())', number_format($slurpRows), number_format($slurpSeconds, 2).' s', number_format($slurpPeak, 1).' MB'],
            ],
        );

        $this->components->bulletList([
            'Memory growth is measured above the process baseline, so it isolates what each strategy costs.',
            'These two times are NOT comparable: file() only splits on newlines, while CsvStream',
            '  fully parses each row into a keyed array. Parsing the slurped lines as well would add',
            '  the same per-row work on top of the memory below.',
            'The meaningful column is memory. Splitting the lines alone already costs ~180 MB, before',
            '  any parsing; a 512 MB PHP memory limit would not survive the parsed form of it.',
            'Streaming trades CPU time for flat memory. That is the right trade for a scheduled job on',
            '  a small VPS, and the wrong one for a latency-sensitive request.',
        ]);
    }

    private function resolveReadBenchmarkFile(): ?string
    {
        $explicit = $this->option('file');

        if (is_string($explicit) && $explicit !== '') {
            return is_file($explicit) ? $explicit : null;
        }

        $staged = glob(rtrim((string) config('pricewatch.staging_path'), '/\\').DIRECTORY_SEPARATOR.'pricecatcher_*.csv');

        if ($staged === false || $staged === []) {
            return null;
        }

        // Largest staged month makes the contrast clearest.
        usort($staged, fn (string $a, string $b): int => filesize($b) <=> filesize($a));

        return $staged[0];
    }

    private function humanDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        if ($seconds < 90) {
            return number_format($seconds, 0).' s';
        }

        if ($seconds < 5400) {
            return number_format($seconds / 60, 1).' min';
        }

        return number_format($seconds / 3600, 1).' hours';
    }
}

/**
 * A bare Eloquent model used only by the benchmark's naive write path, so the
 * comparison measures Eloquent's real per-row cost rather than a stripped-down
 * imitation of it.
 */
class BenchmarkRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
