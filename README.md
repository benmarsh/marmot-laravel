# Marmot for Laravel

Marmot continuously verifies that your business is behaving exactly as expected, and tells you when it isn't. This package is the Laravel SDK: it captures every event your app already fires — including Eloquent lifecycle events for every model — aggregates them into per-minute counts, and ships those counts (names and counts only, never payloads) to your Marmot ingest endpoint.

## Install

```bash
composer require marmot/laravel
```

Then set two environment variables:

```dotenv
MARMOT_API_KEY=your-project-api-key
MARMOT_ENDPOINT=https://your-marmot-app.example.com/v1/events
```

That's it. No listeners to register, no models to annotate. With no API key set, the package is fully inert — no listeners, no network calls.

## What gets captured

- **Model activity**: `eloquent.created` / `eloquent.deleted` for every model, vendor models included — new orders, new signups, deleted media, whatever your app does. (`eloquent.updated` is off by default; opt in with `MARMOT_CAPTURE_UPDATES=true` when raw update volume is itself a signal.)
- **Honest operational signals**: scheduled tasks per task (a free cron dead-man's switch), queue jobs per job class, mail and notifications per class, auth events, outbound HTTP connection failures.
- **Your own events**: anything your app dispatches (`App\Events\OrderShipped`), plus explicit instrumentation:

```php
use Marmot\Laravel\Marmot;

Marmot::event('OrderPlaced');
Marmot::event('EmailsSent', 42);   // optional count
```

Explicit names survive model renames and refactors; discovery watches everything else. Instrument your three most important business moments explicitly and let the wildcard do the rest.

Framework plumbing (routing, caching, view composition, per-request shadows, transitional event pairs) is ignored by default — see the annotated list in `config/marmot.php`.

## The canary

Once a minute, the SDK fires a `marmot.canary` heartbeat through your scheduler. If it ever goes quiet, the whole pipeline — your cron, this SDK, your network path, Marmot's ingest — is suspect. That is the point. Disable with `MARMOT_CANARY=false` if you must.

## Deploy markers

```bash
php artisan marmot:deploy              # posts the current git sha
php artisan marmot:deploy "hotfix"     # optional label
```

Wire it into your deploy so every release records itself:

```json
"post-install-cmd": [
    "@php artisan marmot:deploy || true"
]
```

Charts gain deploy lines, alerts within an hour of a deploy carry "deployed N min earlier" context, and a deploy that soaks incident-free gets a green verdict in Slack. Maintenance windows are detected automatically from `artisan down` / `artisan up` and suppress flatline alerts for their duration.

## Backfill

```bash
php artisan marmot:backfill
```

Inspects your tables' timestamp columns and ships historical **hourly counts** (again: counts only, never rows) so baselines start life already knowing your seasonality, instead of learning it over weeks. Live-collected data always wins over backfill on overlap.

## Delivery guarantees

- Counts aggregate in memory as `{stream, minute} → count` — a 10k-row import collapses to a single counter.
- The buffer flushes after the response via `terminating()`, with a shutdown-function backstop for console contexts, and between jobs on long-running queue workers (debounced; `worker_flush_seconds`, default 15).
- Every flush carries a nonce, so a retried or duplicated request is counted once server-side.
- The outbound call uses a ~1s timeout, never borrows your app's HTTP client, and every failure is swallowed silently: telemetry never becomes your app's problem.

## Configuration

Publish the config file to adjust the ignore list, timeouts, or capture flags:

```bash
php artisan vendor:publish --tag=marmot-config
```

## Development

```bash
composer install
composer test
```
