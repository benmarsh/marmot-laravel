<?php

namespace Marmot\Laravel\Support;

use Throwable;

/**
 * Shipping historical hourly counts to Marmot — shared by the interactive
 * marmot:backfill and the automatic marmot:backfill-auto, so the two can
 * never disagree about how history is counted or validated.
 *
 * The server inserts-or-ignores: a live-collected hour always wins, so
 * re-running fills holes and never double-counts.
 */
class Backfill
{
    /** Server accepts at most 2000 hours per request; 11 weeks fits under it. */
    public const MAX_WEEKS = 11;

    public static function endpoint(): ?string
    {
        $endpoint = config('marmot.endpoint');

        return $endpoint ? preg_replace('#/v1/events$#', '/v1/backfill', (string) $endpoint) : null;
    }

    /**
     * Always returns an array with an `ok` key, so callers can distinguish
     * "server said no, here's why" from "couldn't reach it at all" — the
     * interactive command surfaces the server's message verbatim.
     *
     * @param  list<array{hour: string, count: int}>  $hours
     * @return array<string, mixed>
     */
    public static function send(string $stream, array $hours, bool $dryRun): array
    {
        $endpoint = self::endpoint();

        if (! $endpoint || ! config('marmot.api_key')) {
            return ['ok' => false, 'message' => 'Marmot is not configured (MARMOT_API_KEY / MARMOT_ENDPOINT).'];
        }

        try {
            $response = HttpClient::make()->request('POST', $endpoint, [
                'timeout' => 30,
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer '.config('marmot.api_key'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => ['stream' => $stream, 'dry_run' => $dryRun, 'hours' => $hours],
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: '.$e->getMessage()];
        }

        $body = json_decode((string) $response->getBody(), true) ?: [];
        $ok = $response->getStatusCode() === 200;

        return ['ok' => $ok] + $body + [
            'message' => $ok ? null : "Server returned {$response->getStatusCode()}.",
        ];
    }

    /**
     * Does the recomputed history agree with what Marmot collected live?
     *
     * A systematic shortfall means the table no longer holds rows that
     * history contained — deleted records, or soft-deletes hidden by a
     * global scope. Better to ship nothing than to teach a baseline that
     * normal is lower than it really is: the whole product then under-reacts
     * to real dips. Unattended runs need this more than supervised ones, not
     * less, because nobody is reading the overlap stats.
     *
     * @param  array<string, mixed>  $preview
     */
    public static function agrees(array $preview, float $tolerance = 0.1): bool
    {
        $overlap = $preview['overlap'] ?? [];

        // Nothing collected live for these hours yet: nothing contradicts us.
        if ((int) ($overlap['hours'] ?? 0) === 0) {
            return true;
        }

        $live = (int) ($overlap['live_total'] ?? 0);

        if ($live === 0) {
            return true;
        }

        return abs($live - (int) ($overlap['backfill_total'] ?? 0)) / $live <= $tolerance;
    }
}
