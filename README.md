# PriceWatch MY

Track Malaysian grocery prices from KPDN's PriceCatcher open data.

The Ministry of Domestic Trade and Cost of Living (KPDN) publishes daily price
observations collected by inspectors at premises nationwide — roughly **1.9 million
rows per month** — through [data.gov.my](https://data.gov.my). It is published as
raw monthly CSV and Parquet files, which is useful to analysts and useless to
someone who just wants to know whether chicken got more expensive this month.

This application ingests that data and answers that question.

---

## Contents

- [What it does](#what-it-does)
- [Stack](#stack)
- [Getting started](#getting-started)
- [Architecture](#architecture)
- [Performance](#performance)
- [A data-quality finding](#a-data-quality-finding)
- [Testing](#testing)
- [Scheduled operation](#scheduled-operation)
- [Project status](#project-status)

---

## What it does

- **Browse and search** every tracked item with its current price, filterable by
  state and category.
- **Price trends** per item over 30, 90 or 365 days, rendered as inline SVG — no
  charting library, and the chart is present in the initial HTML.
- **State comparison** showing where an item is cheapest, and the cheapest
  individual premises on the latest day.
- **A watchlist.** Register, set a threshold on any item (nationally or for one
  state), and get emailed when the price crosses it. Evaluated automatically after
  each daily ingest.
- **A pipeline dashboard** at `/pipeline` exposing every ingestion run — rows read,
  stored and quarantined, duration, throughput, and peak memory — plus the
  data-quality findings described below.

---

## Stack

| | |
|---|---|
| Framework | Laravel 13 |
| Language | PHP 8.4 |
| Database | MySQL 8.4 (SQLite for the test suite) |
| Frontend | Livewire 4, Tailwind CSS 4, Vite |
| Auth | Hand-rolled on Laravel's primitives — rate limiting, session regeneration, `Password::defaults()` |
| Tests | PHPUnit 12 — 123 tests, run against both MySQL and SQLite in CI |

---

## Getting started

Requires PHP 8.4+, Composer, Node 20+, and MySQL 8+.

```bash
git clone <your-repo-url> pricewatch-my
cd pricewatch-my
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create the database and point `.env` at it:

```bash
mysql -u root -e "CREATE DATABASE pricewatch_my CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=pricewatch_my
DB_USERNAME=root
DB_PASSWORD=
```

Then migrate, load data, and build the frontend:

```bash
php artisan migrate
php artisan pricewatch:sync --months=1
npm run build
php artisan serve
```

The first sync downloads ~48 MB and takes a few minutes. Staged files are cached
under `storage/app/pricecatcher/`, so subsequent runs skip the download.

### Useful commands

```bash
php artisan pricewatch:sync --month=2026-08     # one specific month
php artisan pricewatch:sync --months=6          # the last six months
php artisan pricewatch:sync --lookups-only      # refresh items and premises only
php artisan pricewatch:sync --force             # re-download and re-ingest
php artisan pricewatch:benchmark                # compare ingestion strategies
php artisan pricewatch:alerts                   # evaluate watchlists on their own
```

Alert emails are queued. With the default `QUEUE_CONNECTION=database` a worker
must be running, or they will sit in the `jobs` table unsent:

```bash
php artisan queue:work
```

With `MAIL_MAILER=log` (the default) the rendered email is written to
`storage/logs/laravel.log` rather than sent, which is enough to demonstrate it.

---

## Architecture

```
data.gov.my
    │  CSV over HTTPS, streamed to disk
    ▼
DatasetSource ──────────► storage/app/pricecatcher/*.csv
    │                       (cached; re-runs skip the download)
    ▼
CsvStream ──────────────► one row at a time, constant memory
    │
    ▼
PriceCatcherImporter ───► validate ──► quarantine unresolvable rows
    │                                   (recorded, not fatal)
    │  chunked bulk upsert
    ▼
price_records  ~1.9M rows/month
    │
    │  DailyPriceAggregator — single INSERT ... SELECT on MySQL
    ▼
daily_item_state_prices  ~43k rows/month
    │
    ▼
PriceQueries ───────────► Livewire components ──► pages
```

Every ingestion attempt is wrapped by `IngestionRunRecorder`, which writes an
audit row with timing, peak memory, row counts and any error — successful or not.

The schema and its trade-offs are documented in **[docs/erd.md](docs/erd.md)**,
including why `price_records` has no foreign keys and why the rollup table exists.

### Key decisions

**Streaming, not slurping.** A month of data is a ~48 MB CSV. `CsvStream` is a
generator over an open file handle, so memory stays flat regardless of file size.
A full import peaks at **44 MB** — less than the file itself.

**Bulk upserts, not Eloquent.** The importer writes through the query builder in
chunks. See [Performance](#performance) for what that is worth.

**Idempotent by construction.** A unique key on `(date, premise_code, item_code)`
means re-ingesting a month *corrects* prices rather than duplicating them. The
importer also compares the source checksum against previous runs and skips
entirely when the upstream file has not changed.

**Reads never touch the fact table.** Every page reads the rollup, which is ~44×
smaller. The single exception is the cheapest-premises list, which genuinely needs
premise-level detail and is bounded to one day.

---

## Performance

Measured on the development machine with `php artisan pricewatch:benchmark`.

### Write strategy — 10,000 rows into a table with the same unique index

| Strategy | Rows/sec | Projected for one month (1.9M rows) |
|---|---:|---:|
| Eloquent model per row | 259 | **2.0 hours** |
| Query builder, one INSERT per row | 252 | 2.1 hours |
| **Chunked bulk upsert** (what the pipeline does) | **14,592** | **2.2 minutes** |

**57.9× faster** than the naive approach. The projection holds up against
reality: the actual August 2026 import ran at 13,248 rows/s.

Note that per-row Eloquent and per-row query builder are within noise of each
other. The cost is not Eloquent's object overhead — it is the **round trip per
row**. Batching is what matters.

### Read strategy — the 47.8 MB August 2026 file

| Strategy | Time | Memory growth |
|---|---:|---:|
| Streamed **and parsed** (`CsvStream`) | 8.35 s | **~0 MB** |
| Slurped, **not parsed** (`file()`) | 0.36 s | **182 MB** |

These times are deliberately *not* comparable: `file()` only splits on newlines
and does no CSV parsing, while `CsvStream` parses every row into a keyed array.
The meaningful column is memory. Merely holding the lines costs 182 MB before any
parsing; the parsed form would be several times that, which is why the naive
version is not viable on a small VPS.

Streaming trades CPU time for flat memory. For a nightly scheduled job that is
the right trade; for a latency-sensitive request it would not be.

### A real import

```
Rows read           1,933,285
Rows upserted       1,901,122
Rows quarantined       32,163  (1.6636%)
Duration                145.9 s
Peak memory              44.0 MB
Throughput           13,248 rows/s
Rollup rebuilt       42,969 rows in 20.4 s
```

---

## A data-quality finding

The August 2026 import quarantined 32,163 rows — 1.66%. Investigating rather than
accepting that number produced the project's most interesting result:

- **0** rows referenced an unknown premise.
- **All 32,163** referenced one of just **14 item codes** — 2023, 2035, 2048,
  2053, 2057, 2059, and 2089–2096 — that appear in the published *price* file but
  are **absent from the published `lookup_item` file**.

The clustering of those codes suggests recently-added items whose lookup entries
have not yet been published. The observations are real; they simply cannot be
attributed to a named product.

This is why the pipeline quarantines rather than aborts. A foreign key would have
rejected the entire 48 MB file over 1.66% of its rows. Instead the run stored the
other 1,901,122 rows and recorded exactly which codes were unresolvable, which the
`/pipeline` page surfaces — and marks as resolved if a later lookup publishes them.

---

## Testing

```bash
php artisan test                        # SQLite in-memory, ~2s
php artisan test -c phpunit.mysql.xml   # against MySQL
```

123 tests covering CSV parsing edge cases (BOM, quoted commas, RFC 4180 escaping,
malformed arity), importer idempotency, quarantine classification, checksum
skipping, aggregation correctness, chart geometry, every Livewire page, auth
(including rate limiting and account enumeration), watchlist ownership, and alert
evaluation.

Three deliberate choices in the suite:

- **`Http::preventStrayRequests()` is enabled globally.** Any test that reaches the
  network without an explicit fake fails loudly. This caught a test that was
  silently downloading 48 MB from the live data portal on every run.
- **The suite runs against both databases in CI.** `DailyPriceAggregator` has a
  MySQL-specific fast path and a portable fallback; running only on SQLite would
  leave the path that production actually uses untested.
- **Dates are pinned to one stored representation.** Running on two databases
  surfaced a real portability bug: Laravel's built-in `date` cast writes through
  the *connection's* datetime format, so a date landed in SQLite as
  `2026-08-30 00:00:00` while MySQL's `DATE` column truncated it to `2026-08-30`.
  Every `where('date', …)` then matched nothing under SQLite while passing under
  MySQL. `App\Casts\DateOnly` fixes it at the source rather than scattering
  `whereDate()` through the query layer, which would also have stopped MySQL using
  the indexes these tables are built around.

---

## Scheduled operation

`routes/console.php` schedules a daily sync of the last two months — the current
month is still being filled in, and the previous one can still be revised. This is
cheap because unchanged months are skipped on checksum.

```bash
php artisan schedule:work    # development
```

In production, add the standard Laravel cron entry:

```
* * * * * cd /path/to/pricewatch-my && php artisan schedule:run >> /dev/null 2>&1
```

---

## Project status

**Working:** ingestion pipeline, aggregation, browse/search, item detail with
trends and state comparison, authentication, watchlist with threshold alerts,
pipeline dashboard, scheduling, CI.

**Not built:** password reset, email verification, and any notification channel
beyond email. The application is not yet deployed to a public URL.

---

## Data attribution

Price data is published by the Ministry of Domestic Trade and Cost of Living
(KPDN) via [data.gov.my](https://data.gov.my) under the PriceCatcher programme.

This is an independent academic project. It is not affiliated with, nor endorsed
by, KPDN or the Government of Malaysia.
