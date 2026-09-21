<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled ingestion
|--------------------------------------------------------------------------
|
| Two months are requested rather than one. The current month is still being
| filled in day by day, and the previous month can still be revised by the
| publisher after it closes. Re-ingesting both is cheap because the importer
| compares the source checksum first and skips a month that has not changed,
| and because the unique key makes a genuine re-ingest a correction rather
| than a duplication.
|
| withoutOverlapping guards against a slow run still holding the database
| when the next day's run begins.
|
| The sync evaluates watchlists itself once the rollup is rebuilt, so alerts are
| always judged against fresh data rather than a stale rollup. `pricewatch:alerts`
| exists to run that step on its own when needed.
|
*/

Schedule::command('pricewatch:sync --months=2')
    ->dailyAt('07:15')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/pricewatch-sync.log'));
