<?php

namespace App\Services\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every read the UI performs, in one place.
 *
 * All of these hit daily_item_state_prices rather than price_records. The rollup is
 * roughly forty times smaller, so a page that charts a year of one item reads a few
 * hundred rows instead of scanning millions. The only query that touches the fact
 * table is cheapestPremises(), which genuinely needs premise-level detail and is
 * bounded to a single day.
 *
 * Averages across states are always weighted by sample_count. Taking a plain mean
 * of the per-state averages would give Perlis the same influence as Selangor, which
 * is a subtly wrong national figure rather than an obviously wrong one.
 */
final class PriceQueries
{
    /**
     * The most recent day for which a rollup exists.
     */
    public function latestDate(): ?string
    {
        $value = Cache::remember(
            'pricewatch:latest-date',
            now()->addMinutes(10),
            fn () => DB::table('daily_item_state_prices')->max('date')
        );

        return $value === null ? null : Carbon::parse($value)->toDateString();
    }

    /**
     * States that actually appear in the data, for filter dropdowns.
     *
     * Note that only plain arrays are cached here, never Collection instances.
     * Laravel restricts which classes a cache payload may unserialize into, so a
     * cached object comes back as __PHP_Incomplete_Class. Caching primitives keeps
     * the payload smaller and independent of framework internals besides.
     *
     * @return Collection<int, string>
     */
    public function states(): Collection
    {
        return collect(Cache::remember(
            'pricewatch:states',
            now()->addHours(6),
            fn (): array => DB::table('premises')
                ->whereNotNull('state')
                ->where('state', '<>', '')
                ->distinct()
                ->orderBy('state')
                ->pluck('state')
                ->all()
        ));
    }

    /**
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return collect(Cache::remember(
            'pricewatch:categories',
            now()->addHours(6),
            fn (): array => DB::table('items')
                ->whereNotNull('item_category')
                ->where('item_category', '<>', '')
                ->distinct()
                ->orderBy('item_category')
                ->pluck('item_category')
                ->all()
        ));
    }

    /**
     * Items with their price on the latest available day.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function searchItems(
        ?string $term = null,
        ?string $state = null,
        ?string $category = null,
        int $perPage = 24,
    ): LengthAwarePaginator {
        $date = $this->latestDate();

        if ($date === null) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        return DB::table('daily_item_state_prices as d')
            ->join('items as i', 'i.item_code', '=', 'd.item_code')
            ->where('d.date', $date)
            ->when($state, fn ($q) => $q->where('d.state', $state))
            ->when($category, fn ($q) => $q->where('i.item_category', $category))
            ->when($term, fn ($q) => $q->where('i.item', 'like', '%'.$term.'%'))
            ->groupBy('d.item_code', 'i.item', 'i.unit', 'i.item_category')
            ->select([
                'd.item_code',
                'i.item',
                'i.unit',
                'i.item_category',
                DB::raw('SUM(d.avg_price * d.sample_count) / SUM(d.sample_count) as avg_price'),
                DB::raw('MIN(d.min_price) as min_price'),
                DB::raw('MAX(d.max_price) as max_price'),
                DB::raw('SUM(d.sample_count) as sample_count'),
            ])
            ->orderBy('i.item')
            ->paginate($perPage);
    }

    /**
     * A daily price series for one item, optionally narrowed to a state.
     *
     * @return Collection<int, object>
     */
    public function series(int $itemCode, ?string $state = null, int $days = 90): Collection
    {
        $date = $this->latestDate();

        if ($date === null) {
            return collect();
        }

        $from = Carbon::parse($date)->subDays($days)->toDateString();

        return DB::table('daily_item_state_prices')
            ->where('item_code', $itemCode)
            ->when($state, fn ($q) => $q->where('state', $state))
            ->whereBetween('date', [$from, $date])
            ->groupBy('date')
            ->select([
                'date',
                DB::raw('SUM(avg_price * sample_count) / SUM(sample_count) as avg_price'),
                DB::raw('MIN(min_price) as min_price'),
                DB::raw('MAX(max_price) as max_price'),
                DB::raw('SUM(sample_count) as sample_count'),
            ])
            ->orderBy('date')
            ->get()
            ->map(function (object $row): object {
                $row->date = Carbon::parse($row->date)->toDateString();
                $row->avg_price = (float) $row->avg_price;
                $row->min_price = (float) $row->min_price;
                $row->max_price = (float) $row->max_price;
                $row->sample_count = (int) $row->sample_count;

                return $row;
            });
    }

    /**
     * Per-state prices for one item on one day, cheapest first.
     *
     * @return Collection<int, object>
     */
    public function stateBreakdown(int $itemCode, ?string $date = null): Collection
    {
        $date ??= $this->latestDate();

        if ($date === null) {
            return collect();
        }

        return DB::table('daily_item_state_prices')
            ->where('item_code', $itemCode)
            ->where('date', $date)
            ->select(['state', 'avg_price', 'min_price', 'max_price', 'sample_count'])
            ->orderBy('avg_price')
            ->get()
            ->map(function (object $row): object {
                $row->avg_price = (float) $row->avg_price;
                $row->min_price = (float) $row->min_price;
                $row->max_price = (float) $row->max_price;
                $row->sample_count = (int) $row->sample_count;

                return $row;
            });
    }

    /**
     * The correctly weighted national average for one item on one day.
     */
    public function nationalAverage(int $itemCode, ?string $date = null): ?float
    {
        $date ??= $this->latestDate();

        if ($date === null) {
            return null;
        }

        $value = DB::table('daily_item_state_prices')
            ->where('item_code', $itemCode)
            ->where('date', $date)
            ->selectRaw('SUM(avg_price * sample_count) / SUM(sample_count) as weighted')
            ->value('weighted');

        return $value === null ? null : (float) $value;
    }

    /**
     * The cheapest premises selling one item on one day.
     *
     * This is the only read that touches price_records. It is bounded to a single
     * day and served by the (item_code, date) index, so it stays an indexed range
     * scan rather than a table scan.
     *
     * @return Collection<int, object>
     */
    public function cheapestPremises(
        int $itemCode,
        ?string $state = null,
        ?string $date = null,
        int $limit = 10,
    ): Collection {
        $date ??= $this->latestDate();

        if ($date === null) {
            return collect();
        }

        return DB::table('price_records as pr')
            ->join('premises as p', 'p.premise_code', '=', 'pr.premise_code')
            ->where('pr.item_code', $itemCode)
            ->where('pr.date', $date)
            ->when($state, fn ($q) => $q->where('p.state', $state))
            ->select([
                'pr.price',
                'p.premise',
                'p.premise_type',
                'p.district',
                'p.state',
            ])
            ->orderBy('pr.price')
            ->limit($limit)
            ->get()
            ->map(function (object $row): object {
                $row->price = (float) $row->price;

                return $row;
            });
    }

    /**
     * The key under which observedPrices() files a given scope.
     *
     * A null or empty state means the national weighted average.
     */
    public static function scopeKey(int $itemCode, ?string $state): string
    {
        return $itemCode.'|'.($state ?? '');
    }

    /**
     * Observed prices for many items at once, both per state and nationally.
     *
     * One query regardless of how many items or watches are involved. Both the
     * watchlist page and the alert evaluator read through this, so a watch is
     * always judged against exactly the number the user is shown.
     *
     * @param  array<int, int>  $itemCodes
     * @return array<string, float> Keyed by scopeKey().
     */
    public function observedPrices(array $itemCodes, ?string $date = null): array
    {
        $date ??= $this->latestDate();

        if ($date === null || $itemCodes === []) {
            return [];
        }

        $rows = DB::table('daily_item_state_prices')
            ->where('date', $date)
            ->whereIn('item_code', array_values(array_unique($itemCodes)))
            ->get(['item_code', 'state', 'avg_price', 'sample_count']);

        $prices = [];
        $weighted = [];
        $samples = [];

        foreach ($rows as $row) {
            $code = (int) $row->item_code;
            $average = (float) $row->avg_price;
            $count = (int) $row->sample_count;

            $prices[self::scopeKey($code, $row->state)] = $average;

            // Accumulate the national figure as a sample-weighted mean rather than
            // an average of the state averages.
            $weighted[$code] = ($weighted[$code] ?? 0.0) + ($average * $count);
            $samples[$code] = ($samples[$code] ?? 0) + $count;
        }

        foreach ($weighted as $code => $total) {
            if ($samples[$code] > 0) {
                $prices[self::scopeKey($code, null)] = $total / $samples[$code];
            }
        }

        return $prices;
    }

    /**
     * Headline counts for the landing page.
     *
     * @return array{observations: int, items: int, premises: int, latest_date: ?string}
     */
    public function coverage(): array
    {
        return Cache::remember('pricewatch:coverage', now()->addMinutes(10), fn (): array => [
            // An exact COUNT(*) on a 20M-row InnoDB table is a full index scan.
            // The rollup carries the same information for a fraction of the cost.
            'observations' => (int) DB::table('daily_item_state_prices')->sum('sample_count'),
            'items' => DB::table('items')->count(),
            'premises' => DB::table('premises')->count(),
            'latest_date' => $this->latestDate(),
        ]);
    }
}
