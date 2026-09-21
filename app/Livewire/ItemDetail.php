<?php

namespace App\Livewire;

use App\Enums\WatchDirection;
use App\Models\Item;
use App\Models\WatchItem;
use App\Services\Queries\PriceQueries;
use App\Support\LineChart;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ItemDetail extends Component
{
    public Item $item;

    #[Url(except: '')]
    public string $state = '';

    #[Url]
    public int $days = 90;

    /** @var array<int, int> */
    public array $rangeOptions = [30, 90, 365];

    public string $threshold = '';

    public string $direction = 'below';

    public function mount(int $itemCode): void
    {
        $this->item = Item::query()->findOrFail($itemCode);
    }

    public function setRange(int $days): void
    {
        // Only accept a range we actually offer, so a crafted query string cannot
        // ask the database for an arbitrarily long scan.
        if (in_array($days, $this->rangeOptions, true)) {
            $this->days = $days;
        }
    }

    /**
     * The current user's watch on this item at the currently selected scope.
     *
     * Scoped to the authenticated user by construction, so there is no way to
     * address another user's watch through this component.
     */
    #[Computed]
    public function watch(): ?WatchItem
    {
        if (! Auth::check()) {
            return null;
        }

        return WatchItem::query()
            ->where('user_id', Auth::id())
            ->where('item_code', $this->item->item_code)
            ->when(
                $this->state === '',
                fn ($query) => $query->whereNull('state'),
                fn ($query) => $query->where('state', $this->state),
            )
            ->first();
    }

    public function saveWatch(): void
    {
        if (! Auth::check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $validated = $this->validate([
            'threshold' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'direction' => ['required', Rule::enum(WatchDirection::class)],
        ]);

        $state = $this->state !== '' ? $this->state : null;

        // The unique index cannot catch a duplicate national watch, because SQL
        // treats each NULL in a unique index as distinct.
        if (WatchItem::existsFor(Auth::id(), $this->item->item_code, $state)) {
            $this->addError('threshold', 'You are already watching this item for '.($state ?? 'Malaysia').'.');

            return;
        }

        WatchItem::create([
            'user_id' => Auth::id(),
            'item_code' => $this->item->item_code,
            'state' => $state,
            'threshold_price' => round((float) $validated['threshold'], 2),
            'direction' => $validated['direction'],
        ]);

        unset($this->watch);
        $this->threshold = '';
    }

    public function removeWatch(): void
    {
        $watch = $this->watch;

        if ($watch === null) {
            return;
        }

        abort_unless($watch->user_id === Auth::id(), 403);

        $watch->delete();

        unset($this->watch);
    }

    public function render(PriceQueries $queries)
    {
        $state = $this->state !== '' ? $this->state : null;

        $series = $queries->series($this->item->item_code, $state, $this->days);
        $national = $queries->nationalAverage($this->item->item_code);

        // Pre-fill the threshold just under the current price, which is the value
        // someone setting an alert almost always wants as a starting point.
        if ($this->threshold === '' && $national !== null) {
            $reference = $state === null
                ? $national
                : ($queries->observedPrices([$this->item->item_code])[PriceQueries::scopeKey($this->item->item_code, $state)] ?? $national);

            $this->threshold = (string) round($reference * 0.95, 2);
        }

        return view('livewire.item-detail', [
            'series' => $series,
            'chart' => LineChart::fromSeries($series),
            'breakdown' => $queries->stateBreakdown($this->item->item_code),
            'national' => $national,
            'cheapest' => $queries->cheapestPremises($this->item->item_code, $state),
            'states' => $queries->states(),
            'latestDate' => $queries->latestDate(),
        ])->title($this->item->displayName().' — PriceWatch MY');
    }
}
