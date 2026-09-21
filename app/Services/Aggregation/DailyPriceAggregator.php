<?php

namespace App\Services\Aggregation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds daily_item_state_prices from the raw observation table.
 *
 * The UI never reads price_records directly. Charting a year of one item from the
 * fact table means scanning millions of rows on every page view; reading the same
 * series from this rollup is a few hundred indexed rows.
 *
 * On MySQL the whole rebuild is a single INSERT ... SELECT ... ON DUPLICATE KEY
 * UPDATE: the data never leaves the database, so no rows travel over the wire and
 * PHP does no work per group. A portable fallback exists for SQLite so the test
 * suite can run without a database server.
 */
final class DailyPriceAggregator
{
    private const FLUSH_SIZE = 1000;

    /**
     * Rebuild every (date, item, state) rollup touched by the given date range.
     *
     * @return int Number of rollup rows written.
     */
    public function rebuild(Carbon $from, Carbon $to): int
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? $this->rebuildInDatabase($from, $to)
            : $this->rebuildPortable($from, $to);
    }

    /**
     * Rebuild the rollups covering a whole YYYY-MM period.
     */
    public function rebuildPeriod(string $period): int
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

        return $this->rebuild($from, $from->copy()->endOfMonth());
    }

    private function rebuildInDatabase(Carbon $from, Carbon $to): int
    {
        // MySQL 8.0.19+ deprecates VALUES() inside ON DUPLICATE KEY UPDATE, so the
        // aggregate is wrapped in a derived table and referenced by its alias.
        $sql = <<<'SQL'
            INSERT INTO daily_item_state_prices
                (date, item_code, state, min_price, max_price, avg_price, sample_count)
            SELECT date, item_code, state, min_price, max_price, avg_price, sample_count
            FROM (
                SELECT pr.date          AS date,
                       pr.item_code     AS item_code,
                       p.state          AS state,
                       MIN(pr.price)    AS min_price,
                       MAX(pr.price)    AS max_price,
                       AVG(pr.price)    AS avg_price,
                       COUNT(*)         AS sample_count
                  FROM price_records pr
                  INNER JOIN premises p ON p.premise_code = pr.premise_code
                 WHERE pr.date BETWEEN ? AND ?
                   AND p.state IS NOT NULL
                   AND p.state <> ''
                 GROUP BY pr.date, pr.item_code, p.state
            ) AS agg
            ON DUPLICATE KEY UPDATE
                min_price    = agg.min_price,
                max_price    = agg.max_price,
                avg_price    = agg.avg_price,
                sample_count = agg.sample_count
        SQL;

        // Note: MySQL reports 1 for an inserted row and 2 for an updated one, so this
        // figure is an activity count rather than an exact row count. The tests
        // assert against the table contents instead.
        return DB::affectingStatement($sql, [$from->toDateString(), $to->toDateString()]);
    }

    private function rebuildPortable(Carbon $from, Carbon $to): int
    {
        $buffer = [];
        $written = 0;

        $aggregate = DB::table('price_records as pr')
            ->join('premises as p', 'p.premise_code', '=', 'pr.premise_code')
            ->whereBetween('pr.date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('p.state')
            ->where('p.state', '<>', '')
            ->groupBy('pr.date', 'pr.item_code', 'p.state')
            ->select([
                'pr.date',
                'pr.item_code',
                'p.state',
                DB::raw('MIN(pr.price) as min_price'),
                DB::raw('MAX(pr.price) as max_price'),
                DB::raw('AVG(pr.price) as avg_price'),
                DB::raw('COUNT(*) as sample_count'),
            ])
            ->cursor();

        foreach ($aggregate as $row) {
            $buffer[] = [
                // MySQL's DATE column hands back 'Y-m-d', but a driver with dynamic
                // typing (SQLite) returns whatever Eloquent's date cast wrote, which
                // is 'Y-m-d H:i:s'. Normalising here keeps the rollup key identical
                // across drivers so the unique index behaves the same everywhere.
                'date' => Carbon::parse($row->date)->toDateString(),
                'item_code' => (int) $row->item_code,
                'state' => $row->state,
                'min_price' => round((float) $row->min_price, 2),
                'max_price' => round((float) $row->max_price, 2),
                'avg_price' => round((float) $row->avg_price, 4),
                'sample_count' => (int) $row->sample_count,
            ];

            if (count($buffer) >= self::FLUSH_SIZE) {
                $written += $this->flush($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $written += $this->flush($buffer);
        }

        return $written;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        DB::table('daily_item_state_prices')->upsert(
            $rows,
            ['date', 'item_code', 'state'],
            ['min_price', 'max_price', 'avg_price', 'sample_count'],
        );

        return count($rows);
    }
}
