<?php

namespace App\Services\Ingestion;

use App\Models\IngestionRun;
use App\Models\Item;
use App\Models\Premise;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads one month of PriceCatcher observations — roughly 1.6 million rows.
 *
 * Everything in here exists to keep that tractable on a laptop:
 *
 *  1. The CSV is streamed from disk one row at a time (CsvStream), so memory stays
 *     flat whether the month is 4 MB or 400 MB.
 *  2. Rows are accumulated into a buffer and written with a single bulk upsert per
 *     chunk via the query builder. Hydrating an Eloquent model per row would mean
 *     1.6M object constructions, 1.6M events and 1.6M INSERT statements.
 *  3. The unique key on (date, premise_code, item_code) makes the whole import
 *     idempotent: re-running a month corrects prices instead of duplicating them.
 *  4. Rows referencing an unknown item or premise are counted and dropped rather
 *     than aborting the run, so one bad row cannot cost a 48 MB re-import.
 *
 * `php artisan pricewatch:benchmark` measures 1 and 2 against the naive approach.
 */
final class PriceCatcherImporter
{
    public function __construct(
        private readonly DatasetSource $source,
        private readonly IngestionRunRecorder $recorder,
        private readonly int $chunkSize,
    ) {}

    public static function make(): self
    {
        return new self(
            DatasetSource::fromConfig(),
            new IngestionRunRecorder,
            (int) config('pricewatch.chunk_size'),
        );
    }

    /**
     * @param  ?Closure(int, int, int): void  $onProgress  Called with (read, upserted, quarantined).
     */
    public function import(string $period, bool $force = false, ?Closure $onProgress = null): IngestionRun
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException("Period must be formatted YYYY-MM, got '{$period}'.");
        }

        return $this->recorder->record(
            IngestionRun::DATASET_PRICES,
            $period,
            function (IngestionRun $run) use ($period, $force, $onProgress): IngestionStats {
                $path = $this->source->fetch(IngestionRun::DATASET_PRICES, $period, $force);
                $checksum = hash_file('sha256', $path);

                // Re-running the scheduler on an unchanged month should cost one
                // checksum, not 1.6 million upserts.
                if (! $force && $this->alreadyIngested($period, $checksum, $run)) {
                    return IngestionStats::unchanged($checksum);
                }

                return $this->load($path, $checksum, $onProgress);
            }
        );
    }

    private function load(string $path, string $checksum, ?Closure $onProgress): IngestionStats
    {
        // Referential integrity lives here rather than in a foreign key. Both lookups
        // are small — hundreds of items, a few thousand premises — so flipping them
        // into plain PHP arrays buys O(1) membership tests with no per-row query.
        // A Collection would also work, but array access avoids a method call on
        // every one of 1.6 million iterations.
        $knownItems = Item::query()->pluck('item_code')->flip()->all();
        $knownPremises = Premise::query()->pluck('premise_code')->flip()->all();

        if ($knownItems === [] || $knownPremises === []) {
            throw new RuntimeException(
                'Lookup tables are empty. Run pricewatch:sync --lookups-only before importing prices.'
            );
        }

        // Laravel appends every executed statement to an in-memory log when it is
        // enabled. Across ~800 bulk upserts that log becomes the largest allocation
        // in the process and quietly defeats the streaming above.
        DB::connection()->disableQueryLog();

        $buffer = [];
        $read = 0;
        $upserted = 0;
        $quarantined = 0;
        $malformed = 0;

        // Keyed by code so collecting the distinct set costs nothing per row.
        $unknownItems = [];
        $unknownPremises = [];

        foreach (CsvStream::rows($path) as $row) {
            $read++;
            $parsed = $this->parse($row);

            // Structurally unusable: bad date, non-numeric code, out-of-range price.
            if ($parsed === null) {
                $quarantined++;
                $malformed++;

                continue;
            }

            // Referentially unresolvable. Tracked separately from malformed rows
            // because the cause is upstream — a lookup file that lags the price
            // file — rather than a corrupt row, and the distinction is what makes
            // the quarantine report actionable.
            $itemKnown = isset($knownItems[$parsed['item_code']]);
            $premiseKnown = isset($knownPremises[$parsed['premise_code']]);

            if (! $itemKnown || ! $premiseKnown) {
                $quarantined++;

                if (! $itemKnown) {
                    $unknownItems[$parsed['item_code']] = true;
                }

                if (! $premiseKnown) {
                    $unknownPremises[$parsed['premise_code']] = true;
                }

                continue;
            }

            $buffer[] = $parsed;

            if (count($buffer) >= $this->chunkSize) {
                $upserted += $this->flush($buffer);
                $buffer = [];

                if ($onProgress !== null) {
                    $onProgress($read, $upserted, $quarantined);
                }
            }
        }

        if ($buffer !== []) {
            $upserted += $this->flush($buffer);

            if ($onProgress !== null) {
                $onProgress($read, $upserted, $quarantined);
            }
        }

        $unknownItemCodes = array_keys($unknownItems);
        $unknownPremiseCodes = array_keys($unknownPremises);
        sort($unknownItemCodes);
        sort($unknownPremiseCodes);

        return new IngestionStats(
            rowsRead: $read,
            rowsUpserted: $upserted,
            rowsQuarantined: $quarantined,
            rowsMalformed: $malformed,
            unknownItemCodes: $unknownItemCodes,
            unknownPremiseCodes: $unknownPremiseCodes,
            checksum: $checksum,
        );
    }

    /**
     * Validate a row's shape and normalise its types. Referential checks happen in
     * load(), which owns the lookup sets and the quarantine bookkeeping.
     *
     * @param  array<string, string>  $row
     * @return ?array<string, mixed> Null means the row is structurally unusable.
     */
    private function parse(array $row): ?array
    {
        $date = trim($row['date'] ?? '');
        $itemCode = filter_var($row['item_code'] ?? '', FILTER_VALIDATE_INT);
        $premiseCode = filter_var($row['premise_code'] ?? '', FILTER_VALIDATE_INT);
        $price = filter_var($row['price'] ?? '', FILTER_VALIDATE_FLOAT);

        if ($itemCode === false || $premiseCode === false || $price === false) {
            return null;
        }

        // A zero or negative price is a collection artefact, not an observation, and
        // would drag every average that includes it towards nonsense.
        if ($price <= 0) {
            return null;
        }

        // The column is decimal(8,2). Anything beyond it is bad data; storing it
        // would silently truncate and corrupt the aggregate.
        if ($price > 999_999.99) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return [
            'date' => $date,
            'premise_code' => $premiseCode,
            'item_code' => $itemCode,
            'price' => round($price, 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        DB::table('price_records')->upsert(
            $rows,
            ['date', 'premise_code', 'item_code'],
            ['price'],
        );

        return count($rows);
    }

    private function alreadyIngested(string $period, string $checksum, IngestionRun $current): bool
    {
        return IngestionRun::query()
            ->where('dataset', IngestionRun::DATASET_PRICES)
            ->where('period', $period)
            ->where('status', IngestionRun::STATUS_COMPLETED)
            ->where('source_checksum', $checksum)
            ->whereKeyNot($current->getKey())
            ->exists();
    }
}
