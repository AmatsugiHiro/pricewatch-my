<div>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Grocery prices across Malaysia</h1>
        <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-slate-600">
            Daily prices collected by KPDN price inspectors at premises nationwide, aggregated per item and state.
            Averages are weighted by the number of observations, so a state with more premises counts proportionally.
        </p>

        @if ($coverage['latest_date'])
            <dl class="mt-5 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 sm:grid-cols-4">
                <div class="bg-white px-4 py-3">
                    <dt class="text-xs font-medium text-slate-500">Observations</dt>
                    <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{{ number_format($coverage['observations']) }}</dd>
                </div>
                <div class="bg-white px-4 py-3">
                    <dt class="text-xs font-medium text-slate-500">Items tracked</dt>
                    <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{{ number_format($coverage['items']) }}</dd>
                </div>
                <div class="bg-white px-4 py-3">
                    <dt class="text-xs font-medium text-slate-500">Premises</dt>
                    <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{{ number_format($coverage['premises']) }}</dd>
                </div>
                <div class="bg-white px-4 py-3">
                    <dt class="text-xs font-medium text-slate-500">Latest data</dt>
                    <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">
                        {{ \Illuminate\Support\Carbon::parse($coverage['latest_date'])->format('j M Y') }}
                    </dd>
                </div>
            </dl>
        @endif
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.3-4.3m1.8-4.4a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
            </svg>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search an item — ayam, telur, beras, minyak…"
                aria-label="Search items"
                class="w-full rounded-lg border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm placeholder:text-slate-400 focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
            >
        </div>

        <select wire:model.live="state" aria-label="Filter by state"
                class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
            <option value="">All states</option>
            @foreach ($states as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>

        <select wire:model.live="category" aria-label="Filter by category"
                class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
            <option value="">All categories</option>
            @foreach ($categories as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>

        @if ($this->hasFilters())
            <button type="button" wire:click="clearFilters"
                    class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">
                Clear
            </button>
        @endif
    </div>

    {{-- Results --}}
    <div wire:loading.class="opacity-50" class="transition-opacity">
        @if ($items->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
                <p class="text-sm font-medium text-slate-900">No items match those filters</p>
                <p class="mt-1 text-sm text-slate-500">Try a broader search, or clear the state and category filters.</p>
            </div>
        @else
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $row)
                    <li>
                        <a href="{{ route('items.show', ['itemCode' => $row->item_code, 'state' => $state]) }}" wire:navigate
                           class="group flex h-full flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-400 hover:shadow-sm">
                            <div>
                                <p class="text-sm font-medium leading-snug text-slate-900 group-hover:underline underline-offset-2">
                                    {{ \Illuminate\Support\Str::of($row->item)->lower()->title() }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $row->unit }}@if ($row->item_category) · {{ $row->item_category }}@endif
                                </p>
                            </div>

                            <div class="mt-4">
                                <p class="text-xl font-semibold tabular-nums text-slate-900">
                                    RM {{ number_format((float) $row->avg_price, 2) }}
                                </p>
                                <p class="mt-0.5 text-xs tabular-nums text-slate-500">
                                    RM {{ number_format((float) $row->min_price, 2) }}–{{ number_format((float) $row->max_price, 2) }}
                                    · {{ number_format((int) $row->sample_count) }} premises
                                </p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-8">
                {{ $items->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</div>
