<?php

namespace Marmot\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Marmot\Laravel\Support\Backfill;
use Marmot\Laravel\Support\ModelStreams;
use Throwable;

/**
 * The standing rule: any table-backed stream that hasn't been backfilled
 * yet, gets backfilled.
 *
 * Enabled with MARMOT_BACKFILL=true and run from the host app's scheduler,
 * so a customer's baselines exist within minutes of installing instead of
 * after a fortnight of watching. Deliberately a standing preference rather
 * than a one-shot at install: a model added next year gets the same instant
 * baseline the day it ships, and flipping the flag on later catches
 * everything that came before.
 *
 * No queue needed. This already runs in a CLI process with nobody waiting
 * on it, which is the only reason the work needed getting off the request
 * path in the first place.
 */
class AutoBackfillCommand extends Command
{
    private const DONE_PREFIX = 'marmot:backfilled:';

    private const RETRY_PREFIX = 'marmot:backfill-retry:';

    /** Long enough that a never-live model costs ~4 scans a day, not ~96. */
    private const RETRY_AFTER_SECONDS = 21_600; // 6 hours

    protected $signature = 'marmot:backfill-auto
        {--weeks= : History to fetch (default marmot.backfill.weeks)}
        {--force : Re-backfill streams already done}';

    protected $description = 'Backfill any table-backed stream that has not been backfilled yet';

    public function handle(): int
    {
        if (! config('marmot.backfill.automatic') && ! $this->option('force')) {
            $this->info('Automatic backfill is off (set MARMOT_BACKFILL=true).');

            return self::SUCCESS;
        }

        if (! config('marmot.api_key') || ! config('marmot.endpoint')) {
            $this->error('Marmot is not configured (MARMOT_API_KEY / MARMOT_ENDPOINT).');

            return self::FAILURE;
        }

        // Hours are bucketed in UTC. A non-UTC app whose timestamps are
        // stored in local time would backfill into shifted buckets and
        // poison the baseline — and unattended, nobody is here to confirm.
        // The interactive command asks; this one declines.
        if (config('app.timezone') !== 'UTC') {
            $this->warn('App timezone is not UTC — skipping automatic backfill. Run marmot:backfill by hand to confirm your timestamps are UTC.');

            return self::SUCCESS;
        }

        $weeks = min(
            (int) ($this->option('weeks') ?: config('marmot.backfill.weeks', 8)),
            Backfill::MAX_WEEKS,
        );

        $from = Carbon::now('UTC')->startOfHour()->subWeeks($weeks);
        $done = 0;

        // Sequential by construction: one table scan at a time keeps the
        // load on the customer's database bounded and predictable.
        foreach (ModelStreams::all() as $stream => $candidate) {
            if (! $this->option('force') && ($this->alreadyDone($stream) || $this->backingOff($stream))) {
                continue;
            }

            if ($this->backfill($stream, $candidate, $from)) {
                $done++;
            }
        }

        $this->info($done === 0 ? 'Nothing to backfill.' : "Backfilled {$done} stream(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array{model: class-string, table: string, column: string}  $candidate
     */
    private function backfill(string $stream, array $candidate, Carbon $from): bool
    {
        // Skip tables too big to scan without being a nuisance. Unknown size
        // (null) fails closed for the same reason: the ceiling exists to
        // protect a database we can't measure.
        $rows = ModelStreams::approximateRows($candidate['table']);
        $ceiling = (int) config('marmot.backfill.max_rows', 20_000_000);

        if ($rows === null || $rows > $ceiling) {
            $this->line("Skipping {$candidate['table']}: ".($rows === null
                ? "can't be sized cheaply"
                : number_format($rows).' rows is over the ceiling'));

            return false;
        }

        try {
            $hours = ModelStreams::hourlyCounts($candidate, $from);
        } catch (Throwable $e) {
            $this->error("Count failed for {$candidate['table']}: ".$e->getMessage());

            return false;
        }

        if ($hours === []) {
            $this->markDone($stream); // Genuinely no history — nothing to retry.

            return false;
        }

        $preview = Backfill::send($stream, $hours, dryRun: true);

        if (! $preview['ok']) {
            // Usually "not seen live yet": the model exists but has never
            // fired a created event. Retry, because it may fire tomorrow —
            // but back off hard, because plenty of models (Settings, a
            // MagicLink, a rarely-touched lookup) will NEVER fire, and
            // rescanning their tables every quarter hour forever would make
            // this a permanent tax on the host app's database. Backing off
            // costs at most a few hours' delay on a stream that does appear.
            $this->retryLater($stream);
            $this->line("Not ready: {$stream} — {$preview['message']}");

            return false;
        }

        // Unattended runs need the agreement check MORE than supervised
        // ones, not less: nobody is reading the overlap stats, so a table
        // that has been pruned would quietly teach a baseline that normal is
        // lower than it really is.
        if (! Backfill::agrees($preview)) {
            $this->warn("Skipping {$stream}: recomputed history disagrees with what Marmot collected live — this table has probably lost rows. Run marmot:backfill by hand to inspect.");
            $this->markDone($stream); // Don't retry a disagreement every tick.

            return false;
        }

        $result = Backfill::send($stream, $hours, dryRun: false);

        if (! $result['ok']) {
            return false;
        }

        $this->markDone($stream);
        $this->info(sprintf('%s: %d hour(s) written.', $stream, $result['inserted'] ?? 0));

        return true;
    }

    /**
     * Per-stream, so a new model added later is backfilled on its own
     * schedule rather than being missed by a global "already ran" flag.
     *
     * The marker only saves a re-scan: the server inserts-or-ignores, so a
     * repeat backfill writes nothing. A cleared cache costs one redundant
     * count, never wrong data.
     */
    private function alreadyDone(string $stream): bool
    {
        try {
            return Cache::has(self::DONE_PREFIX.md5($stream));
        } catch (Throwable) {
            return false;
        }
    }

    private function markDone(string $stream): void
    {
        try {
            Cache::forever(self::DONE_PREFIX.md5($stream), time());
        } catch (Throwable) {
            // No cache: worst case we recount next tick and write nothing.
        }
    }

    /**
     * Is this stream serving a back-off from a previous "not ready"?
     *
     * Without this the scheduler rescans every not-yet-live model's table
     * every quarter hour, forever — on a real app that was eleven tables,
     * ~1,000 pointless scans a day. A monitoring tool has no business
     * doing that to the database it is supposed to be watching over.
     */
    private function backingOff(string $stream): bool
    {
        try {
            return Cache::has(self::RETRY_PREFIX.md5($stream));
        } catch (Throwable) {
            return false;
        }
    }

    private function retryLater(string $stream): void
    {
        try {
            Cache::put(self::RETRY_PREFIX.md5($stream), time(), self::RETRY_AFTER_SECONDS);
        } catch (Throwable) {
            // No cache: falls back to retrying every tick, as before.
        }
    }
}
