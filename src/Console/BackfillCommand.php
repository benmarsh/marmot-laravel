<?php

namespace Marmot\Laravel\Console;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private ?ClientInterface $client = null;

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

        // Querying the table directly (not the model) deliberately includes
        // soft-deleted rows: capture-time truth. Hard deletes still
        // undercount — the overlap stats below are the detector.
        $query = DB::table($candidate['table'])
            ->selectRaw($this->hourExpression($candidate['column']).' as hour')
            ->selectRaw('count(*) as aggregate')
            ->where($candidate['column'], '>=', $from);

        if ($where !== '' && $where !== null) {
            $query->whereRaw($where);
        }

        $started = microtime(true);

        try {
            $hours = $query->groupBy('hour')->orderBy('hour')->get()
                ->map(fn (object $row) => ['hour' => $row->hour, 'count' => (int) $row->aggregate])
                ->all();
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

        $overlap = $preview['overlap'];

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
     * @param  list<array{hour: string, count: int}>  $hours
     * @return ?array<string, mixed> decoded response, or null on failure (already reported)
     */
    private function send(string $stream, array $hours, bool $dryRun): ?array
    {
        $endpoint = preg_replace('#/v1/events$#', '/v1/backfill', (string) config('marmot.endpoint'));

        try {
            $response = $this->client()->request('POST', $endpoint, [
                'timeout' => 30,
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer '.config('marmot.api_key'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'stream' => $stream,
                    'dry_run' => $dryRun,
                    'hours' => $hours,
                ],
            ]);
        } catch (Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return null;
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if ($response->getStatusCode() !== 200) {
            $this->error($body['message'] ?? "Server returned {$response->getStatusCode()}.");

            return null;
        }

        return $body;
    }

    /**
     * Candidate models: everything in the configured namespace with a
     * CREATED_AT column that exists on its table.
     *
     * @return list<array{model: string, table: string, column: string, indexed: ?bool, rows: int, earliest: ?string}>
     */
    private function discover(): array
    {
        $path = config('marmot.backfill.models_path') ?? app_path('Models');
        $namespace = rtrim(config('marmot.backfill.models_namespace', 'App\\Models'), '\\');

        $candidates = [];

        foreach (glob($path.'/*.php') ?: [] as $file) {
            $class = $namespace.'\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $instance = new $class;
            $table = $instance->getTable();
            $column = $instance::CREATED_AT;

            if ($column === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $candidates[] = [
                'model' => $class,
                'table' => $table,
                'column' => $column,
                'indexed' => $this->hasLeftmostIndex($table, $column),
                'rows' => $this->approximateRows($table),
                'earliest' => $this->earliestRecord($table, $column),
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

    /**
     * Approximate row count for the listing — statistics where the engine
     * keeps them, so discovery never pays the full-scan cost it's warning
     * about.
     */
    private function approximateRows(string $table): int
    {
        try {
            $prefixed = DB::getTablePrefix().$table;

            $approximate = match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => DB::selectOne(
                    'select table_rows as aggregate from information_schema.tables where table_schema = database() and table_name = ?',
                    [$prefixed],
                )?->aggregate,
                'pgsql' => DB::selectOne(
                    'select reltuples::bigint as aggregate from pg_class where relname = ?',
                    [$prefixed],
                )?->aggregate,
                default => null,
            };

            if ($approximate !== null && (int) $approximate > 0) {
                return (int) $approximate;
            }

            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Earliest record for the listing, estimated via the primary key (the
     * lowest id is almost always the oldest row) — never a min() over an
     * unindexed timestamp.
     */
    private function earliestRecord(string $table, string $column): ?string
    {
        try {
            if (! Schema::hasColumn($table, 'id')) {
                return null;
            }

            $value = DB::table($table)->where('id', DB::table($table)->min('id'))->value($column);

            return $value ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Hour truncation is dialect-specific (the ingest upsert's usual trade). */
    private function hourExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "date_format(`{$column}`, '%Y-%m-%d %H:00:00')",
            'pgsql' => "to_char(date_trunc('hour', \"{$column}\"), 'YYYY-MM-DD HH24:00:00')",
            default => "strftime('%Y-%m-%d %H:00:00', \"{$column}\")",
        };
    }

    private function client(): ClientInterface
    {
        // 'marmot.http_client' is the test seam — never resolve the global
        // ClientInterface binding, which host-app packages configure with
        // their own base URIs, headers, and retry middleware.
        return $this->client ??= $this->laravel->bound('marmot.http_client')
            ? $this->laravel->make('marmot.http_client')
            : new Client;
    }
}
