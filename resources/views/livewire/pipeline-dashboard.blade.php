<div>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Data pipeline</h1>
        <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-slate-600">
            Every ingestion attempt is audited, successful or not. Peak memory is recorded to evidence that a
            ~48&nbsp;MB source file is streamed rather than loaded into memory, and quarantined rows are broken down
            by cause so that a data gap upstream is distinguishable from a bug here.
        </p>
    </div>

    <dl class="mb-8 grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 sm:grid-cols-3">
        <div class="bg-white px-4 py-3">
            <dt class="text-xs font-medium text-slate-500">Rows read</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{{ number_format($totals['rows_read']) }}</dd>
        </div>
        <div class="bg-white px-4 py-3">
            <dt class="text-xs font-medium text-slate-500">Rows stored</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{{ number_format($totals['rows_upserted']) }}</dd>
        </div>
        <div class="bg-white px-4 py-3">
            <dt class="text-xs font-medium text-slate-500">Rows quarantined</dt>
            <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{{ number_format($totals['rows_quarantined']) }}</dd>
        </div>
    </dl>

    {{-- Data quality finding --}}
    @if ($unresolvedItems->isNotEmpty())
        <section class="mb-8 rounded-xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="text-sm font-semibold text-amber-900">Unresolvable reference codes</h2>
            <p class="mt-1 max-w-3xl text-sm leading-relaxed text-amber-800">
                These item codes appear in the published price files but are absent from the published
                <code class="rounded bg-amber-100 px-1 py-0.5 text-xs">lookup_item</code> file, so their observations
                cannot be attributed to a named product. They are counted and dropped rather than aborting the import —
                a foreign key would have rejected the entire file over them.
            </p>

            <ul class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($unresolvedItems as $entry)
                    <li>
                        <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium tabular-nums
                                     {{ $entry['resolved'] ? 'border-emerald-300 bg-emerald-100 text-emerald-900' : 'border-amber-300 bg-amber-100 text-amber-900' }}">
                            {{ $entry['code'] }}
                            @if ($entry['resolved'])
                                <span class="font-normal">· since published</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Runs --}}
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-3.5">
            <h2 class="text-sm font-semibold text-slate-900">Recent runs</h2>
        </div>

        @if ($runs->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-slate-900">No ingestion runs recorded yet</p>
                <p class="mt-1 text-sm text-slate-500">
                    Run <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">php artisan pricewatch:sync</code> to load data.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-5 py-2.5 font-medium">Dataset</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Read</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Stored</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Quarantined</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Duration</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Peak memory</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Throughput</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($runs as $run)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-2.5">
                                    <span class="font-medium text-slate-900">{{ $run->dataset }}</span>
                                    @if ($run->period)
                                        <span class="text-slate-500">· {{ $run->period }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    @php
                                        $tone = match ($run->status) {
                                            \App\Models\IngestionRun::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-800',
                                            \App\Models\IngestionRun::STATUS_SKIPPED => 'bg-slate-100 text-slate-600',
                                            \App\Models\IngestionRun::STATUS_FAILED => 'bg-red-100 text-red-800',
                                            default => 'bg-blue-100 text-blue-800',
                                        };
                                    @endphp
                                    <span class="inline-block rounded px-1.5 py-0.5 text-xs font-medium {{ $tone }}">
                                        {{ $run->status }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($run->rows_read) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($run->rows_upserted) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums {{ $run->rows_quarantined > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                                    {{ number_format($run->rows_quarantined) }}
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ $run->durationForHumans() }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ $run->peakMemoryForHumans() }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-700">
                                    {{ $run->throughput() !== null ? number_format($run->throughput()).' rows/s' : '—' }}
                                </td>
                            </tr>

                            @if ($run->error)
                                <tr class="bg-red-50">
                                    <td colspan="8" class="px-5 py-2 text-xs text-red-800">{{ $run->error }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
