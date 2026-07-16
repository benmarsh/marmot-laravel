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

## How it works

- A wildcard event listener captures every event name fired by the app, minus framework plumbing (routing, caching, view composition — see `config/marmot.php`).
- Counts are aggregated in memory as `{stream, minute} → count`, so a 10k-row import collapses to a single counter, not 10k entries.
- The buffer flushes once per request via `terminating()` — after the response is sent — with a shutdown-function backstop for console contexts. The outbound call uses a ~1s timeout and every failure is swallowed silently: telemetry never becomes your app's problem.

## Configuration

Publish the config file if you need to adjust the ignore list or timeout:

```bash
php artisan vendor:publish --tag=marmot-config
```

## Development

```bash
composer install
composer test
```
