<?php

namespace Marmot\Laravel\Support;

use Illuminate\Support\Facades\Cache;
use Marmot\Laravel\Jobs\RunBackfillInstruction;
use Throwable;

/**
 * The inbound half of the control channel (build brief §4a steps 5-6):
 * instructions arrive on the ingest response, and this decides what — if
 * anything — to do about them.
 *
 * Two hard rules, both enforced here:
 *
 * 1. The SDK holds the veto. An instruction names a stream; this resolves
 *    that name against the local model scan and refuses anything it
 *    wouldn't have reported itself. A table name from the wire is never
 *    read, so Marmot can never make an app query an arbitrary table.
 * 2. Never in the flush path. Work is dispatched to the queue (scheduler
 *    fallback) and never executed inline — the control channel is
 *    lightweight, the execution isn't, and flush must never slow the app.
 */
class InstructionHandler
{
    private const SEEN_PREFIX = 'marmot:instruction:';

    private const PARKED_KEY = 'marmot:instructions:parked';

    /**
     * @param  array<int, array<string, mixed>>  $instructions
     */
    public static function handle(array $instructions): void
    {
        // Console contexts only, for the same reason SchemaReporter is:
        // resolving an instruction runs the model scan (glob, reflect,
        // instantiate, two schema queries per model), and that must never
        // happen inside a visitor's web request. The scheduler's canary
        // flushes every minute, so instructions are still picked up
        // promptly — and delivery is at-least-once, so anything a web
        // flush ignores simply rides the next console one.
        if (! app()->runningInConsole()) {
            return;
        }

        foreach ($instructions as $instruction) {
            try {
                self::dispatch($instruction);
            } catch (Throwable) {
                // Telemetry never throws into the host app (delivery NFR).
            }
        }
    }

    /** @param array<string, mixed> $instruction */
    private static function dispatch(array $instruction): void
    {
        $id = (string) ($instruction['id'] ?? '');
        $stream = (string) ($instruction['stream'] ?? '');

        if ($id === '' || $stream === '' || ($instruction['type'] ?? null) !== 'backfill') {
            return;
        }

        // Delivery is at-least-once — the same instruction rides every flush
        // response until its chunks land, so without this a 12-week backfill
        // would be dispatched once a minute for as long as it took to run.
        if (! self::claim($id)) {
            return;
        }

        // The veto: resolve locally or refuse. Note this ignores everything
        // the instruction says about tables (it says nothing, by protocol)
        // and re-derives from the model — so a renamed or deleted model
        // fails safely instead of querying something unexpected.
        if (ModelStreams::resolve($stream) === null) {
            return;
        }

        $weeks = max(1, min(52, (int) ($instruction['weeks'] ?? 12)));

        // The sync queue driver runs dispatched jobs INLINE — which, from a
        // flush, means inside the host app's web request. That's precisely
        // the thing the never-in-the-flush-path guardrail forbids, so a
        // sync-queue app parks the work instead and marmot:process-instructions
        // picks it up from the scheduler, where a long scan is harmless.
        if (self::queueRunsInline()) {
            self::park($id, $stream, $weeks);

            return;
        }

        RunBackfillInstruction::dispatch($id, $stream, $weeks);
    }

    /** Would dispatching execute the job here and now? */
    private static function queueRunsInline(): bool
    {
        try {
            $default = config('queue.default');

            return $default === null
                || $default === 'sync'
                || config("queue.connections.{$default}.driver") === 'sync';
        } catch (Throwable) {
            return true; // Unsure means don't risk running it inline.
        }
    }

    /**
     * Park work for the scheduler. Keyed per instruction, so parking is
     * idempotent and a never-collected instruction simply expires.
     */
    private static function park(string $id, string $stream, int $weeks): void
    {
        try {
            $parked = Cache::get(self::PARKED_KEY, []) ?: [];
            $parked[$id] = ['stream' => $stream, 'weeks' => $weeks];

            Cache::put(self::PARKED_KEY, $parked, 86_400);
        } catch (Throwable) {
            // Nothing to park to; the instruction expires server-side.
        }
    }

    /**
     * Hand over everything parked, clearing it. Called from the scheduled
     * command — a CLI context, where running a bulk scan inline is fine.
     *
     * @return array<string, array{stream: string, weeks: int}>
     */
    public static function drainParked(): array
    {
        try {
            $parked = Cache::get(self::PARKED_KEY, []) ?: [];
            Cache::forget(self::PARKED_KEY);

            return $parked;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * First-claim wins, so a duplicate instruction (or two workers reading
     * responses concurrently) can't run the same backfill twice. Cache
     * unavailable is a fail-CLOSED case: an unclaimable instruction is
     * skipped rather than risking a repeated bulk scan of the customer's
     * database.
     */
    private static function claim(string $id): bool
    {
        try {
            return Cache::add(self::SEEN_PREFIX.$id, time(), 86_400);
        } catch (Throwable) {
            return false;
        }
    }
}
