<?php

namespace App\Livewire;

use App\Models\WatchItem;
use App\Services\Queries\PriceQueries;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('My watchlist — PriceWatch MY')]
class Watchlist extends Component
{
    public function removeWatch(int $watchId): void
    {
        $watch = WatchItem::query()
            ->where('user_id', Auth::id())
            ->findOrFail($watchId);

        $watch->delete();
    }

    public function render(PriceQueries $queries)
    {
        $watches = WatchItem::query()
            ->with('item')
            ->where('user_id', Auth::id())
            ->latest('id')
            ->get();

        // One query for every watched item's price, per state and nationally,
        // rather than one lookup per row.
        $prices = $queries->observedPrices($watches->pluck('item_code')->all());

        $rows = $watches->map(function (WatchItem $watch) use ($prices): array {
            $observed = $prices[$watch->priceKey()] ?? null;

            return [
                'watch' => $watch,
                'observed' => $observed,
                'breached' => $observed !== null && $watch->isBreachedBy($observed),
                'distance' => $observed === null
                    ? null
                    : $observed - (float) $watch->threshold_price,
            ];
        });

        return view('livewire.watchlist', [
            'rows' => $rows,
            'latestDate' => $queries->latestDate(),
        ]);
    }
}
