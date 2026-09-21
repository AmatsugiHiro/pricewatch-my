# Deployment

The application is split across three services, all on free tiers:

| Piece | Service | Why |
|---|---|---|
| Database | Aiven MySQL (1 GB free) | Real MySQL, no credit card |
| Scheduled ingest | GitHub Actions | 6 hours per job; a web host cannot run a multi-minute import |
| Web | Vercel | Serverless, does not sleep between visits |

This document covers the first two. The web tier is a separate step.

---

## Why the ingest does not live on the web host

The importer streams a ~48 MB file and takes two to three minutes per month. A
serverless function caps out at 15 minutes and cannot run a queue worker at all,
so the scheduled job would not fit even if it were a sensible thing to put in a
request. GitHub Actions allows six hours per job and costs nothing on a public
repository, so the long-running work stays out of the request path entirely.

---

## Part 1 — The database

### 1. Create the service

1. Sign up at [aiven.io](https://aiven.io) (no credit card required).
2. Create a service → **MySQL** → **Free** plan.
3. Pick the region closest to you — `google-asia-southeast1` (Singapore) is the
   nearest to Malaysia.
4. Wait for it to report **Running** (a few minutes).

### 2. Collect the connection details

From the service overview page, note:

- Host, Port, User, Password
- Database name (Aiven calls the default one `defaultdb`)
- **CA Certificate** — download it; Aiven requires TLS

### 3. Check it before trusting it

Locally, point a throwaway `.env` at it and confirm the schema builds:

```bash
php artisan migrate --force
php artisan db:show
```

If that succeeds, the credentials and TLS are right.

---

## Part 2 — The scheduled ingest

### 1. Add the repository secrets

In GitHub: **Settings → Secrets and variables → Actions → New repository secret**.

| Secret | Value |
|---|---|
| `APP_KEY` | Your `APP_KEY` from `.env`, including the `base64:` prefix |
| `APP_URL` | The public URL once the web tier exists; any placeholder until then |
| `DB_HOST` | Aiven host |
| `DB_PORT` | Aiven port |
| `DB_DATABASE` | `defaultdb` |
| `DB_USERNAME` | Aiven user |
| `DB_PASSWORD` | Aiven password |
| `DB_SSL_CA` | The **entire contents** of the CA certificate file, including the `-----BEGIN/END CERTIFICATE-----` lines |

Never commit any of these. `.env` is gitignored for exactly this reason.

### 2. Seed the database

Run the workflow manually once to backfill:

**Actions → Ingest prices → Run workflow → months: `12`**

This takes roughly 30–60 minutes. It ingests each month oldest-first and prunes
after each one, so storage stays bounded throughout rather than peaking at 1.9 GB
and blowing the free tier.

### 3. It then runs itself

The schedule is `15 23 * * *` UTC, which is **07:15 Malaysia time** daily. It
ingests the last two months — cheap, because an unchanged month is skipped on its
checksum — rebuilds the rollup, evaluates watchlists and prunes.

---

## Retention: why the live database is not 1.9 GB

A full year of raw observations is 20 million rows and about 1.8 GB, of which the
indexes are more than half. That does not fit a free database, and almost none of
it is ever read: every chart and comparison is served from
`daily_item_state_prices`, and the single query that touches raw records only ever
looks at one day.

So `pricewatch:prune` keeps the rollup forever and ages out the raw rows behind
it:

```bash
php artisan pricewatch:prune --keep-days=70 --dry-run   # inspect first
php artisan pricewatch:prune --keep-days=70
```

That leaves roughly 4.2 million rows (~375 MB) plus the ~73 MB rollup — about
450 MB, comfortably inside 1 GB, and stable month to month because each new month
pushes an old one out.

Nothing is permanently lost. The import is idempotent, so any month can be
reconstructed from data.gov.my at any time:

```bash
php artisan pricewatch:sync --month=2025-10
```

### The 70-day guard

`--keep-days` will refuse anything under 70 days without `--force`. The scheduled
sync rebuilds the rollup for the **last two months** from raw records, so pruning
inside that window would leave the next run aggregating rows that no longer exist
and silently blanking a month of charts. Seventy days covers two months plus
slack.

---

## Things that bit us, so they do not bite again

**The cache is shared, and the ingest is a different machine.** The web tier
caches the latest date and the coverage counters. `pricewatch:sync` calls
`PriceQueries::flushCaches()` at the end for this reason, and the workflow sets
`CACHE_STORE=database` so that invalidation actually reaches the web tier rather
than clearing a cache nobody reads.

**There is no queue worker in CI.** The workflow sets `QUEUE_CONNECTION=sync` so
alert mail is sent inline. With `MAIL_MAILER=log` it is written to the job log
rather than delivered; set a real mailer secret when you want actual email.

**TLS needs a file on disk, not a string.** Laravel reads `MYSQL_ATTR_SSL_CA` as a
path. The workflow writes the secret to `storage/app/aiven-ca.pem` via the
environment rather than interpolating it into the shell, because a PEM is
multi-line and would otherwise break on its own newlines.
