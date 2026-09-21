<?php

namespace App\Services\Alerts;

use App\Models\PriceAlert;
use App\Models\WatchItem;
use App\Notifications\PriceAlertNotification;
use App\Services\Queries\PriceQueries;

/**
 * Checks every watch against the latest rollup and notifies on new breaches.
 *
 * Two properties matter here:
 *
 *  - **Constant query count.** Every watched item's price, per state and
 *    nationally, is fetched in one query up front. Evaluating a thousand watches
 *    costs the same number of queries as evaluating one.
 *
 *  - **Idempotence.** The unique index on (watch_item_id, observed_on) means a
 *    second pass over the same day finds the existing alert row rather than
 *    creating another, so re-running the scheduler cannot notify a user twice for
 *    the same day. That is enforced by the database, not by a flag we remember to
 *    check.
 */
final class WatchEvaluator
{
    public function __construct(private readonly PriceQueries $queries) {}

    public function evaluate(?string $date = null): WatchEvaluationResult
    {
        $date ??= $this->queries->latestDate();

        if ($date === null) {
            return new WatchEvaluationResult;
        }

        $watches = WatchItem::query()->with(['user', 'item'])->get();

        if ($watches->isEmpty()) {
            return new WatchEvaluationResult;
        }

        $prices = $this->queries->observedPrices($watches->pluck('item_code')->all(), $date);

        $evaluated = 0;
        $withoutData = 0;
        $breached = 0;
        $notified = 0;

        foreach ($watches as $watch) {
            $evaluated++;

            $observed = $prices[$watch->priceKey()] ?? null;

            // A watch on a state where this item was not surveyed that day is not a
            // failure; there is simply nothing to judge it against.
            if ($observed === null) {
                $withoutData++;

                continue;
            }

            if (! $watch->isBreachedBy($observed)) {
                continue;
            }

            $breached++;

            $alert = PriceAlert::firstOrCreate(
                [
                    'watch_item_id' => $watch->id,
                    'observed_on' => $date,
                ],
                [
                    'observed_price' => round($observed, 4),
                    'threshold_price' => $watch->threshold_price,
                ],
            );

            // Already recorded on an earlier pass over this same day.
            if (! $alert->wasRecentlyCreated) {
                continue;
            }

            $watch->user->notify(new PriceAlertNotification($alert, $watch, $observed));

            $alert->forceFill(['notified_at' => now()])->save();
            $watch->forceFill(['last_notified_at' => now()])->save();

            $notified++;
        }

        return new WatchEvaluationResult(
            evaluated: $evaluated,
            withoutData: $withoutData,
            breached: $breached,
            notified: $notified,
        );
    }
}
