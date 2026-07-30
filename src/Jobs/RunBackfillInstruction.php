<?php

namespace Marmot\Laravel\Jobs;

use GuzzleHttp\ClientInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Marmot\Laravel\Support\HttpClient;
use Marmot\Laravel\Support\ModelStreams;
use Throwable;

/**
 * Executes a panel-triggered backfill (build brief §4a step 7, §4c).
 *
 * Runs on the queue, never in the flush path. Chunked a week at a time,
 * oldest first, sequentially — the first chunk doubles as the validation
 * pass ("validate small before committing large"), because its dry-run
 * overlap check compares recomputed history against what Marmot already
 * collected live. Poor agreement stops the run rather than shipping
 * twelve weeks of quietly wrong data — which is also how the soft-delete
 * undercount gets caught for free.
 */
class RunBackfillInstruction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A bulk historical scan is never urgent; retrying it aggressively is worse. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private string $instructionId,
        private string $stream,
        private int $weeks,
    ) {
    }

    public function handle(): void
    {
        // Re-resolve at execution time, not dispatch time: the veto applies
        // to the moment the query would actually run.
        $candidate = ModelStreams::resolve($this->stream);

        if ($candidate === null) {
            return;
        }

        $endpoint = preg_replace('#/v1/events$#', '/v1/backfill', (string) config('marmot.endpoint'));

        if (! $endpoint || ! config('marmot.api_key')) {
            return;
        }

        $start = Carbon::now('UTC')->startOfHour()->subWeeks($this->weeks);
        $validated = false;

        for ($chunk = 1; $chunk <= $this->weeks; $chunk++) {
            $from = $start->copy()->addWeeks($chunk - 1);
            $to = $from->copy()->addWeek();

            try {
                $hours = ModelStreams::hourlyCounts($candidate, $from, $to);
            } catch (Throwable) {
                return; // A failing count query stops the run; nothing is half-shipped.
            }

            if ($hours === []) {
                continue; // A genuinely empty week is not a failure.
            }

            // Validate small before committing large (§4c) — on the first
            // chunk that actually HAS data, not merely the first chunk. The
            // oldest weeks of a young table are routinely empty, and an
            // empty week proves nothing about agreement.
            if (! $validated) {
                $preview = $this->send($endpoint, $hours, $chunk, dryRun: true);

                if ($preview === null || ! $this->agrees($preview)) {
                    return;
                }

                $validated = true;
            }

            if ($this->send($endpoint, $hours, $chunk, dryRun: false) === null) {
                return;
            }
        }
    }

    /**
     * Does the recomputed week agree with what Marmot collected live? A
     * systematic shortfall means the table no longer holds rows history
     * contained (hard deletes, or soft deletes filtered by a global scope)
     * — better to ship nothing than to teach a baseline a lie.
     *
     * @param  array<string, mixed>  $preview
     */
    private function agrees(array $preview): bool
    {
        $overlap = $preview['overlap'] ?? [];
        $hours = (int) ($overlap['hours'] ?? 0);

        if ($hours === 0) {
            return true; // No live data to compare against yet: nothing contradicts us.
        }

        $live = (int) ($overlap['live_total'] ?? 0);
        $backfill = (int) ($overlap['backfill_total'] ?? 0);

        if ($live === 0) {
            return true;
        }

        // Within a tenth of live totals across the overlapping window.
        return abs($live - $backfill) / $live <= 0.1;
    }

    /**
     * @param  list<array{hour: string, count: int}>  $hours
     * @return ?array<string, mixed>
     */
    private function send(string $endpoint, array $hours, int $chunk, bool $dryRun): ?array
    {
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
                    'stream' => $this->stream,
                    'dry_run' => $dryRun,
                    'hours' => $hours,
                    // Provenance, so the server can track progress without a
                    // separate ack call — and so a retried chunk is
                    // recognised rather than counted twice.
                    'instruction_id' => $this->instructionId,
                    'chunk' => $chunk,
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    private function client(): ClientInterface
    {
        return HttpClient::make();
    }
}
