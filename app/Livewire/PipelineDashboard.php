<?php

namespace App\Livewire;

use App\Models\IngestionRun;
use App\Models\Item;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Makes the ingestion pipeline observable from the browser instead of the log file.
 *
 * This page is also where the project's data-quality findings surface: which
 * reference codes the publisher's price files contain but their lookup files do not.
 */
#[Layout('components.layouts.app')]
#[Title('Data pipeline — PriceWatch MY')]
class PipelineDashboard extends Component
{
    public function render()
    {
        $runs = IngestionRun::query()
            ->latest('id')
            ->limit(25)
            ->get();

        return view('livewire.pipeline-dashboard', [
            'runs' => $runs,
            'unresolvedItems' => $this->unresolvedItemCodes($runs),
            'totals' => [
                'rows_read' => $runs->sum('rows_read'),
                'rows_upserted' => $runs->sum('rows_upserted'),
                'rows_quarantined' => $runs->sum('rows_quarantined'),
            ],
        ]);
    }

    /**
     * Item codes that appeared in price data but were missing from the published
     * lookup, across every recorded run, and whether they have since been published.
     *
     * @param  Collection<int, IngestionRun>  $runs
     * @return Collection<int, array{code: int, resolved: bool}>
     */
    private function unresolvedItemCodes(Collection $runs): Collection
    {
        $codes = $runs
            ->pluck('unknown_codes')
            ->filter()
            ->flatMap(fn (array $payload): array => $payload['items'] ?? [])
            ->unique()
            ->sort()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        // A code quarantined last month may have been published since. Resolving in
        // one query keeps this page to a fixed query count no matter how many codes
        // are listed.
        $known = Item::query()
            ->whereIn('item_code', $codes)
            ->pluck('item', 'item_code');

        return $codes->map(fn (int $code): array => [
            'code' => $code,
            'resolved' => $known->has($code),
            'name' => $known->get($code),
        ]);
    }
}
