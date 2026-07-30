<?php

namespace Marmot\Laravel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Throwable;

/**
 * Which local models sit behind which streams — the stream→table mapping,
 * resolved locally from the model itself (build brief §4).
 *
 * This is the SDK's authority and its veto. Marmot may ask for work by
 * stream name; the SDK decides whether that name resolves to something it
 * would have reported itself, and what table it means. A table name from
 * the wire is never trusted — never even read — so a compromised or
 * mistaken instruction can't make the host app query arbitrary tables.
 *
 * Shared by marmot:backfill (interactive) and the control channel
 * (panel-triggered), so the two can't drift on what a stream means.
 */
class ModelStreams
{
    /**
     * Every model with a usable created-at column, keyed by its live stream
     * name — so backfilled history and live capture stitch into the same
     * stream by construction.
     *
     * @return array<string, array{model: class-string, table: string, column: string, soft_deletes: bool}>
     */
    public static function all(): array
    {
        $path = config('marmot.backfill.models_path') ?? app_path('Models');
        $namespace = rtrim(config('marmot.backfill.models_namespace', 'App\\Models'), '\\');

        // Memoised on the container, not statically: a scan costs a glob, a
        // reflection and two schema queries per model, and one operation can
        // resolve several streams (a batch of instructions, a whole schema
        // report). Container lifetime is exactly right — fresh per request,
        // per job, and per test — where a static would serve one test's scan
        // to the next.
        $key = 'marmot.model-streams:'.$path.'|'.$namespace;

        if (app()->bound($key)) {
            return app()->make($key);
        }

        $out = [];

        foreach (glob($path.'/*.php') ?: [] as $file) {
            $class = $namespace.'\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            try {
                if ((new ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                $instance = new $class;
                $table = $instance->getTable();
                $column = $instance::CREATED_AT;

                if ($column === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }
            } catch (Throwable) {
                continue; // A model that can't be instantiated isn't a candidate.
            }

            $out[self::streamFor($class)] = [
                'model' => $class,
                'table' => $table,
                'column' => $column,
                'soft_deletes' => in_array(SoftDeletes::class, class_uses_recursive($class), true),
            ];
        }

        app()->instance($key, $out);

        return $out;
    }

    /** The live capture stream name for a model — the stitching contract. */
    public static function streamFor(string $class): string
    {
        return 'eloquent.created: '.$class;
    }

    /**
     * Resolve a stream name to a local model, or null. Null means refuse:
     * the caller must do nothing rather than guess a table.
     *
     * @return array{model: class-string, table: string, column: string, soft_deletes: bool}|null
     */
    public static function resolve(string $stream): ?array
    {
        return self::all()[$stream] ?? null;
    }

    /**
     * Hourly counts for a window, read straight from the table.
     *
     * Deliberately queries the table rather than the model, so soft-deleted
     * rows are included: capture-time truth is what live collection recorded.
     * Hard deletes still undercount — the server's overlap check is the
     * detector for that.
     *
     * `$whereRaw` narrows the count to match what the live event means (the
     * interactive command's optional filter); null counts every row.
     *
     * @param  array{table: string, column: string}  $candidate
     * @return list<array{hour: string, count: int}>
     */
    public static function hourlyCounts(array $candidate, Carbon $from, ?Carbon $to = null, ?string $whereRaw = null): array
    {
        $query = self::connection()
            ->table($candidate['table'])
            ->selectRaw(self::hourExpression($candidate['column']).' as hour')
            ->selectRaw('count(*) as aggregate')
            ->where($candidate['column'], '>=', $from);

        if ($to !== null) {
            $query->where($candidate['column'], '<', $to);
        }

        if ($whereRaw !== null && $whereRaw !== '') {
            $query->whereRaw($whereRaw);
        }

        return $query->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn (object $row) => ['hour' => (string) $row->hour, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * Read replica when one is configured (§4c): backfill is a bulk scan,
     * and it must never touch the connection serving production writes.
     * "Never affects your app" is easiest to break on the single most
     * impressive moment in onboarding.
     */
    public static function connection(): \Illuminate\Database\ConnectionInterface
    {
        $name = config('marmot.backfill.read_connection');

        if ($name && config("database.connections.{$name}")) {
            return DB::connection($name);
        }

        return DB::connection();
    }

    /**
     * Approximate row count, or null when it can't be known cheaply.
     *
     * NEVER counts. This runs automatically rather than at a human's
     * request, so a count(*) here would be an unbounded scan of a customer
     * table triggered by telemetry — the "never affects your app" promise
     * broken by the thing that promises it. Engine statistics where they
     * exist; the primary key's high-water mark otherwise (an index lookup,
     * and an OVER-estimate once rows have been deleted, which is the safe
     * direction for a "too heavy to backfill" ceiling).
     *
     * Null means unknown, and unknown must never be read as small: the
     * server declines to offer a backfill rather than guess.
     */
    public static function approximateRows(string $table): ?int
    {
        try {
            $connection = self::connection();
            $prefixed = $connection->getTablePrefix().$table;

            $approximate = match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $connection->selectOne(
                    'select table_rows as aggregate from information_schema.tables where table_schema = database() and table_name = ?',
                    [$prefixed],
                )?->aggregate,
                'pgsql' => $connection->selectOne(
                    'select reltuples::bigint as aggregate from pg_class where relname = ?',
                    [$prefixed],
                )?->aggregate,
                default => null,
            };

            if ($approximate !== null && (int) $approximate > 0) {
                return (int) $approximate;
            }

            // Statistics absent or never gathered (a freshly-migrated
            // Postgres table reports reltuples 0 or -1 until it's analysed).
            if (! Schema::hasColumn($table, 'id')) {
                return null;
            }

            $max = $connection->table($table)->max('id');

            return $max === null ? 0 : (int) $max;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Earliest record, estimated via the primary key (the lowest id is
     * almost always the oldest row) — never a min() over an unindexed
     * timestamp.
     */
    public static function earliestRecord(string $table, string $column): ?string
    {
        try {
            $connection = self::connection();

            if (! Schema::hasColumn($table, 'id')) {
                return null;
            }

            $value = $connection->table($table)
                ->where('id', $connection->table($table)->min('id'))
                ->value($column);

            return $value ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Hour truncation is dialect-specific (the ingest upsert's usual trade). */
    public static function hourExpression(string $column): string
    {
        return match (self::connection()->getDriverName()) {
            'mysql', 'mariadb' => "date_format(`{$column}`, '%Y-%m-%d %H:00:00')",
            'pgsql' => "to_char(date_trunc('hour', \"{$column}\"), 'YYYY-MM-DD HH24:00:00')",
            default => "strftime('%Y-%m-%d %H:00:00', \"{$column}\")",
        };
    }
}
