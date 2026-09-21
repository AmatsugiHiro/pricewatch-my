<div>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">My watchlist</h1>
        <p class="mt-1.5 text-sm text-slate-600">
            Checked after each daily ingest.
            @if ($latestDate)
                Prices shown are for {{ \Illuminate\Support\Carbon::parse($latestDate)->format('j M Y') }}.
            @endif
        </p>
    </div>

    @if ($rows->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-900">Nothing on your watchlist yet</p>
            <p class="mt-1 text-sm text-slate-500">Open any item and set a threshold to start tracking it.</p>
            <a href="{{ route('items.index') }}" wire:navigate
               class="mt-4 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">
                Browse items
            </a>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($rows as $row)
                @php $watch = $row['watch']; @endphp
                <li class="rounded-xl border bg-white p-4 {{ $row['breached'] ? 'border-emerald-300 ring-1 ring-emerald-200' : 'border-slate-200' }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a href="{{ route('items.show', ['itemCode' => $watch->item_code, 'state' => $watch->state ?? '']) }}"
                               wire:navigate class="text-sm font-medium text-slate-900 underline-offset-2 hover:underline">
                                {{ $watch->item->displayName() }}
                            </a>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $watch->item->unit }} · {{ $watch->locationLabel() }}
                            </p>
                            <p class="mt-2 text-xs text-slate-600">
                                Alert when the price {{ $watch->direction->label() }}
                                <span class="font-medium tabular-nums text-slate-900">RM {{ number_format((float) $watch->threshold_price, 2) }}</span>
                            </p>
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                @if ($row['observed'] === null)
                                    <p class="text-sm text-slate-400">No data</p>
                                @else
                                    <p class="text-lg font-semibold tabular-nums text-slate-900">
                                        RM {{ number_format($row['observed'], 2) }}
                                    </p>
                                    @if ($row['breached'])
                                        <p class="text-xs font-medium text-emerald-700">Threshold met</p>
                                    @else
                                        <p class="text-xs tabular-nums text-slate-500">
                                            RM {{ number_format(abs($row['distance']), 2) }} away
                                        </p>
                                    @endif
                                @endif
                            </div>

                            <button type="button"
                                    wire:click="removeWatch({{ $watch->id }})"
                                    wire:confirm="Remove this item from your watchlist?"
                                    class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:border-red-300 hover:bg-red-50 hover:text-red-700">
                                Remove
                            </button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
