<div>
    <a href="{{ route('items.index') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 transition hover:text-slate-900">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m0 0 7 7m-7-7 7-7"/>
        </svg>
        All items
    </a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $item->displayName() }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $item->unit }}@if ($item->item_category) · {{ $item->item_category }}@endif
                @if ($latestDate)
                    · as of {{ \Illuminate\Support\Carbon::parse($latestDate)->format('j M Y') }}
                @endif
            </p>
        </div>

        @if ($national !== null)
            <div class="text-right">
                <p class="text-xs font-medium text-slate-500">
                    {{ $state !== '' ? $state : 'National' }} average
                </p>
                <p class="text-3xl font-semibold tabular-nums text-slate-900">
                    RM {{ number_format($national, 2) }}
                </p>
            </div>
        @endif
    </div>

    {{-- Watch --}}
    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
        @guest
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Get told when this price moves</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Sign in to set a threshold for this item.</p>
                </div>
                <a href="{{ route('login') }}" wire:navigate
                   class="shrink-0 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">
                    Sign in
                </a>
            </div>
        @else
            @if ($this->watch)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">You are watching this item</h2>
                        <p class="mt-0.5 text-xs text-slate-600">
                            Alert when the {{ $this->watch->locationLabel() }} price
                            {{ $this->watch->direction->label() }}
                            <span class="font-medium tabular-nums text-slate-900">RM {{ number_format((float) $this->watch->threshold_price, 2) }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('watchlist') }}" wire:navigate
                           class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                            My watchlist
                        </a>
                        <button type="button" wire:click="removeWatch"
                                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-red-300 hover:bg-red-50 hover:text-red-700">
                            Stop watching
                        </button>
                    </div>
                </div>
            @else
                <h2 class="text-sm font-semibold text-slate-900">Watch this item</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    Checked after each daily ingest, for {{ $state !== '' ? $state : 'Malaysia as a whole' }}.
                </p>

                <form wire:submit="saveWatch" class="mt-3 flex flex-wrap items-end gap-3">
                    <div>
                        <label for="direction" class="block text-xs font-medium text-slate-700">Alert me when the price</label>
                        <select id="direction" wire:model="direction"
                                class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                            @foreach (\App\Enums\WatchDirection::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="threshold" class="block text-xs font-medium text-slate-700">Threshold (RM)</label>
                        <input id="threshold" type="number" step="0.01" min="0.01" wire:model="threshold"
                               class="mt-1 w-32 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm tabular-nums focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                    </div>

                    <button type="submit"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">
                        Watch
                    </button>
                </form>

                @error('threshold') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('direction') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            @endif
        @endguest
    </section>

    {{-- Chart --}}
    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Price trend</h2>
                @if ($chart)
                    @php $change = $chart->changePercent(); @endphp
                    <p class="mt-0.5 text-xs text-slate-500">
                        <span class="font-medium tabular-nums {{ $change > 0 ? 'text-red-600' : ($change < 0 ? 'text-emerald-600' : 'text-slate-600') }}">
                            {{ $change > 0 ? '+' : '' }}{{ number_format($change, 1) }}%
                        </span>
                        over the last {{ $days }} days
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <select wire:model.live="state" aria-label="Filter by state"
                        class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900">
                    <option value="">All states</option>
                    @foreach ($states as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>

                <div class="inline-flex rounded-lg border border-slate-300 p-0.5" role="group" aria-label="Time range">
                    @foreach ($rangeOptions as $option)
                        <button type="button" wire:click="setRange({{ $option }})"
                                class="rounded-md px-2.5 py-1 text-xs font-medium transition {{ $days === $option ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                            {{ $option }}d
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($chart)
            <svg viewBox="0 0 {{ $chart->width }} {{ $chart->height }}" class="h-auto w-full" role="img"
                 aria-label="Line chart of the average price over the last {{ $days }} days">
                <defs>
                    <linearGradient id="priceFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="rgb(15 23 42)" stop-opacity="0.12"/>
                        <stop offset="100%" stop-color="rgb(15 23 42)" stop-opacity="0"/>
                    </linearGradient>
                </defs>

                @foreach ($chart->gridLines as $line)
                    <line x1="32" y1="{{ $line['y'] }}" x2="{{ $chart->width - 32 }}" y2="{{ $line['y'] }}"
                          stroke="rgb(226 232 240)" stroke-width="1"/>
                    <text x="{{ $chart->width - 28 }}" y="{{ $line['y'] + 3 }}" font-size="10" fill="rgb(100 116 139)">
                        {{ number_format($line['value'], 2) }}
                    </text>
                @endforeach

                <path d="{{ $chart->areaPath }}" fill="url(#priceFill)"/>
                <path d="{{ $chart->linePath }}" fill="none" stroke="rgb(15 23 42)" stroke-width="2"
                      stroke-linejoin="round" stroke-linecap="round"/>

                @php $last = $chart->lastPoint(); @endphp
                <circle cx="{{ $last['x'] }}" cy="{{ $last['y'] }}" r="3.5" fill="rgb(15 23 42)"/>

                <text x="32" y="{{ $chart->height - 10 }}" font-size="10" fill="rgb(100 116 139)">
                    {{ \Illuminate\Support\Carbon::parse($chart->points[0]['label'])->format('j M') }}
                </text>
                <text x="{{ $chart->width - 32 }}" y="{{ $chart->height - 10 }}" font-size="10"
                      fill="rgb(100 116 139)" text-anchor="end">
                    {{ \Illuminate\Support\Carbon::parse($last['label'])->format('j M') }}
                </text>
            </svg>
        @else
            <p class="py-12 text-center text-sm text-slate-500">
                Not enough data points to draw a trend for this selection.
            </p>
        @endif
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- Price by state --}}
        <section class="rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-900">Price by state</h2>
                <p class="mt-0.5 text-xs text-slate-500">Cheapest first, on the latest available day.</p>
            </div>

            @if ($breakdown->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No state data for this item.</p>
            @else
                @php $ceiling = $breakdown->max('avg_price') ?: 1; @endphp
                <ul class="divide-y divide-slate-100">
                    @foreach ($breakdown as $row)
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            <span class="w-32 shrink-0 truncate text-sm text-slate-700">{{ $row->state }}</span>
                            <span class="relative h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <span class="absolute inset-y-0 left-0 rounded-full bg-slate-800"
                                      style="width: {{ max(3, round($row->avg_price / $ceiling * 100)) }}%"></span>
                            </span>
                            <span class="w-20 shrink-0 text-right text-sm font-medium tabular-nums text-slate-900">
                                RM {{ number_format($row->avg_price, 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Cheapest premises --}}
        <section class="rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-900">Cheapest premises</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ $state !== '' ? $state : 'Nationwide' }}, on the latest available day.
                </p>
            </div>

            @if ($cheapest->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No premise-level data for this selection.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($cheapest as $row)
                        <li class="flex items-start justify-between gap-3 px-5 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-900">{{ \Illuminate\Support\Str::of($row->premise)->lower()->title() }}</p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $row->premise_type }}@if ($row->district) · {{ $row->district }}, {{ $row->state }}@endif
                                </p>
                            </div>
                            <span class="shrink-0 text-sm font-medium tabular-nums text-slate-900">
                                RM {{ number_format($row->price, 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
