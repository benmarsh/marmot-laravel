<?php

namespace Marmot\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Marmot\Laravel\Support\Backfill;
use Marmot\Laravel\Support\ModelStreams;
use Throwable;

/**
 * Backfill historical hourly counts into Marmot (M2 Task 6).
 *
 * Discovery scans model classes, not tables: a model yields its table, its
 * CREATED_AT column, and — decisively — the exact live stream name
 * ("eloquent.created: FQCN"), so backfilled history and live capture stitch
 * into the same stream by construction.
 *
 * Two-pass ship: a dry-run first, so the overlap-agreement stats (how the
 * recomputed history compares to what Marmot collected live) are on screen
 * before anything is written. The server inserts-or-ignores: live hours
 * always win, and re-runs only fill holes.
 */
class BackfillCommand extends Command
{
    protected $signature = 'marmot:backfill';

    protected $description = 'Backfill historical hourly counts from your tables into Marmot';

    public function handle(): int
    {
        if (! config('marmot.api_key') || ! config('marmot.endpoint')) {
            $this->error('Marmot is not configured (MARMOT_API_KEY / MARMOT_ENDPOINT).');

            return self::FAILURE;
        }

        if (config('app.timezone') !== 'UTC') {
            $this->warn(sprintf(
                'App timezone is %s, not UTC. Marmot buckets hours in UTC; timestamps stored in a other timezone will backfill into shifted buckets and poison baselines.',
                config('app.timezone'),
            ));

            if (! $this->confirm('Timestamps in this database ARE stored as UTC — continue?')) {
                return self::FAILURE;
            }
        }

        $candidates = $this->discover();

        if ($candidates === []) {
            $this->error('No backfillable models found (need a class extending Model with a CREATED_AT column).');

            return self::FAILURE;
        }

        $this->table(
            ['Model', 'Table', '~Rows', 'Earliest record', 'Date column'],
            array_map(fn (array $c) => [
                $c['model'],
                $c['table'],
                number_format($c['rows']),
                $c['earliest'] ?? '—',
                $c['column'].($c['indexed'] === false ? '  (not indexed)' : ''),
            ], $candidates),
        );

        $chosen = $this->choice(
            'Which models should backfill? (comma-separated)',
            array_column($candidates, 'model'),
            null,
            null,
            true,
        );

        $weeks = (int) $this->ask('How many weeks of history?', (string) config('marmot.backfill.weeks', 8));

        if ($weeks < 1 || $weeks > 11) {
            $this->error('Weeks must be between 1 and 11 (the server accepts at most 2000 hours per stream).');

            return self::FAILURE;
        }

        $from = Carbon::now('UTC')->startOfHour()->subWeeks($weeks);
        $failures = 0;

        foreach ($candidates as $candidate) {
            if (! in_array($candidate['model'], $chosen, true)) {
                continue;
            }

            $failures += $this->backfill($candidate, $from) ? 0 : 1;
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{model: string, table: string, column: string, indexed: ?bool, rows: int}  $candidate
     */
    private function backfill(array $candidate, Carbon $from): bool
    {
        $stream = 'eloquent.created: '.$candidate['model'];

        $this->newLine();
        $this->info("{$candidate['model']} → `{$stream}`");

        if ($candidate['indexed'] === false && $candidate['rows'] > 100_000) {
            $this->warn(sprintf(
                '%s is not indexed on %s (~%s rows) — the count may take a while. It reads without locking, so your app is not blocked.',
                $candidate['table'], $candidate['column'], number_format($candidate['rows']),
            ));
        }

        $where = $this->ask('Optional WHERE clause (must match what the live event means — blank for none)', '');

        $started = microtime(true);

        try {
            // Shared with marmot:backfill-auto, so both paths count the same
            // way AND both honour marmot.backfill.read_connection — this
            // command used to query the default connection directly, which
            // quietly bypassed the read-replica guarantee.
            $hours = ModelStreams::hourlyCounts($candidate, $from, whereRaw: $where ?: null);
        } catch (Throwable $e) {
            $this->error('Count query failed: '.$e->getMessage());

            return false;
        }

        $this->line(sprintf('Computed %d hour(s) in %.1fs.', count($hours), microtime(true) - $started));

        if ($hours === []) {
            $this->warn('Nothing to backfill in the window.');

            return true;
        }

        $preview = $this->send($stream, $hours, dryRun: true);

        if ($preview === null) {
            return false;
        }

        $overlap = $preview['overlap'] ?? [];

        $this->line(sprintf('%d new hour(s) would be written; %d already collected live (live always wins).',
            $preview['new_hours'], $overlap['hours']));

        if ($overlap['hours'] > 0) {
            $this->line(sprintf(
                'Overlap agreement: %d/%d hours exact, max delta %d (live total %d vs backfill %d). A systematic shortfall here usually means deleted rows — this table may undercount history.',
                $overlap['exact_matches'], $overlap['hours'], $overlap['max_delta'],
                $overlap['live_total'], $overlap['backfill_total'],
            ));
        }

        if (! $this->confirm('Ship it?', true)) {
            return true;
        }

        $result = $this->send($stream, $hours, dryRun: false);

        if ($result === null) {
            return false;
        }

        $this->info(sprintf('Backfilled: %d hour(s) written, %d left to live data.',
            $result['inserted'], $result['skipped']));

        return true;
    }

    /**
     * Shared with marmot:backfill-auto via Backfill::send, so both paths
     * count and validate history identically.
     *
     * @param  list<array{hour: string, count: int}>  $hours
     * @return ?array<string, mixed> decoded response, or null on failure (already reported)
     */
    private function send(string $stream, array $hours, bool $dryRun): ?array
    {
        $result = Backfill::send($stream, $hours, $dryRun);

        if (! $result['ok']) {
            $this->error($result['message']);

            return null;
        }

        return $result;
    }

    /**
     * Candidate models: everything in the configured namespace with a
     * CREATED_AT column that exists on its table.
     *
     * Delegates to ModelStreams so the interactive command and the control
     * channel can never disagree about what a stream means — the same
     * two-copies drift that bit the ignore list in M1.
     *
     * @return list<array{model: string, table: string, column: string, indexed: ?bool, rows: int, earliest: ?string}>
     */
    private function discover(): array
    {
        $candidates = [];

        foreach (ModelStreams::all() as $candidate) {
            $candidates[] = [
                'model' => $candidate['model'],
                'table' => $candidate['table'],
                'column' => $candidate['column'],
                'indexed' => $this->hasLeftmostIndex($candidate['table'], $candidate['column']),
                'rows' => ModelStreams::approximateRows($candidate['table']),
                'earliest' => ModelStreams::earliestRecord($candidate['table'], $candidate['column']),
            ];
        }

        return $candidates;
    }

    /** Only an index with the date column LEFTMOST helps the range scan. */
    private function hasLeftmostIndex(string $table, string $column): ?bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['columns'][0] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null; // Older framework without getIndexes: skip the note.
        }
    }

    /** Hour truncation is dialect-specific (the ingest upsert's usual trade). */
    private function hourExpression(string $column): string
    {
        return ModelStreams::hourExpression($column);
    }
}
