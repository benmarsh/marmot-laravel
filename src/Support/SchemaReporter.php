<?php

namespace Marmot\Laravel\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The outbound half of the control channel (build brief §4a step 2): tells
 * Marmot which table sits behind which stream, so the panel can offer a
 * backfill for the ones worth it.
 *
 * Names and counts only — table name, timestamp column, row count, earliest
 * timestamp, whether soft-deletes exist. Never column values. Marmot learns
 * the shape of your schema, never its contents.
 *
 * Opt-in by config, default off (§4b): a remote party making your app
 * enumerate its own schema requires an explicit local yes. This is the
 * SDK's veto and it is not negotiable from the wire.
 */
class SchemaReporter
{
    private const CACHE_KEY = 'marmot:schema-reported';

    /**
     * The report to attach to this flush, or [] for nothing to say.
     *
     * Event-driven, not first-connect-only (§4b): first connect is actually
     * the worst moment — the SDK has just installed and may have discovered
     * nothing yet. So: report a stream the first time it's resolvable, plus
     * a daily refresh (models get added, tables get renamed).
     *
     * @return list<array<string, mixed>>
     */
    public static function due(): array
    {
        if (! self::enabled()) {
            return [];
        }

        // Console contexts only — the scheduler's canary flushes every
        // minute, so reports still go out promptly, but building one never
        // happens inside a user's web request. Discovery instantiates every
        // model and asks each table for statistics; individually cheap,
        // collectively not free, and none of it is the visitor's business.
        if (! app()->runningInConsole()) {
            return [];
        }

        try {
            $candidates = ModelStreams::all();
        } catch (Throwable) {
            return []; // Discovery trouble is never the host app's problem.
        }

        if ($candidates === []) {
            return [];
        }

        $state = self::state();
        // now(), not time(): the host app's clock is the one that matters,
        // and it's the one tests can travel.
        $now = now()->getTimestamp();
        $refreshDue = ($now - (int) ($state['refreshed_at'] ?? 0)) >= 86_400;
        $reported = $state['streams'] ?? [];

        $out = [];

        foreach ($candidates as $stream => $candidate) {
            if (! self::allowed($candidate['table'])) {
                continue;
            }

            // New to us, or the daily refresh has come round.
            if (! $refreshDue && in_array($stream, $reported, true)) {
                continue;
            }

            $out[] = [
                'stream' => $stream,
                'table' => $candidate['table'],
                'timestamp_column' => $candidate['column'],
                'row_count_approx' => ModelStreams::approximateRows($candidate['table']),
                'earliest_at' => ModelStreams::earliestRecord($candidate['table'], $candidate['column']),
                'soft_deletes' => $candidate['soft_deletes'],
            ];
        }

        // Advance the clock whenever the refresh came round, even if nothing
        // was reportable — else a fully-disallowed install re-runs discovery
        // on every single flush.
        if ($out !== [] || $refreshDue) {
            // Not-due implies refreshed_at was set (that's what the check
            // above tests), so there's no missing-value case to guard.
            self::remember(array_keys($candidates), $refreshDue ? $now : (int) $state['refreshed_at']);
        }

        return $out;
    }

    /**
     * The local yes. Either blanket-on, or an explicit table allowlist —
     * an allowlist implies consent for exactly those tables.
     */
    public static function enabled(): bool
    {
        return (bool) config('marmot.schema_reporting', false)
            || config('marmot.schema_reporting_tables', []) !== [];
    }

    private static function allowed(string $table): bool
    {
        $allowlist = config('marmot.schema_reporting_tables', []);

        return $allowlist === [] || in_array($table, $allowlist, true);
    }

    /** @return array{streams?: list<string>, refreshed_at?: int} */
    private static function state(): array
    {
        try {
            return Cache::get(self::CACHE_KEY, []) ?: [];
        } catch (Throwable) {
            // No cache available: report every flush rather than never. The
            // server upserts, so repetition is wasteful, not harmful.
            return [];
        }
    }

    /** @param list<string> $streams */
    private static function remember(array $streams, int $refreshedAt): void
    {
        try {
            Cache::put(self::CACHE_KEY, [
                'streams' => array_values($streams),
                'refreshed_at' => $refreshedAt,
            ], 604_800);
        } catch (Throwable) {
            // Best-effort bookkeeping only.
        }
    }
}
