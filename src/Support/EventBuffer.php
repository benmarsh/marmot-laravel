<?php

namespace Marmot\Laravel\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

class EventBuffer
{
    public const SDK_VERSION = '0.1.0';

    /** Shared with AutoBackfillCommand: streams seen firing, pending history. */
    public const OBSERVED_PREFIX = 'marmot:observed:';

    private const KEY_DELIMITER = "\x00";

    /**
     * Aggregated counts, not individual events: "{stream}\x00{minute}" => count.
     * A 10k-event burst on one stream in one minute is a single map entry.
     *
     * @var array<string, int>
     */
    private array $counts = [];

    public function __construct(private ?ClientInterface $client = null)
    {
    }

    public function push(string $streamName, int $count = 1): void
    {
        $key = $streamName.self::KEY_DELIMITER.gmdate('Y-m-d\TH:i:00\Z');

        $this->counts[$key] = ($this->counts[$key] ?? 0) + $count;
    }

    /**
     * The pending {stream, minute, count} entries.
     *
     * @return array<int, array{stream: string, minute: string, count: int}>
     */
    public function pending(): array
    {
        $events = [];

        foreach ($this->counts as $key => $count) {
            [$stream, $minute] = explode(self::KEY_DELIMITER, $key, 2);

            $events[] = ['stream' => $stream, 'minute' => $minute, 'count' => $count];
        }

        return $events;
    }

    /**
     * Ship pending counts to the ingest endpoint. The buffer is cleared
     * whether or not the request succeeds, and every failure is swallowed:
     * telemetry must never surface as the host app's problem.
     */
    public function flush(): void
    {
        if ($this->counts === []) {
            return;
        }

        $events = $this->pending();
        $this->counts = [];

        try {
            $endpoint = config('marmot.endpoint');

            if (! $endpoint) {
                return;
            }

            $this->noteObservable($events);

            $timeout = (float) config('marmot.timeout', 1.0);

            $this->client()->request('POST', $endpoint, [
                'timeout' => $timeout,
                'connect_timeout' => $timeout,
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer '.config('marmot.api_key'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'source' => 'laravel',
                    'sdk_version' => self::SDK_VERSION,
                    // One nonce per flush: however this POST gets duplicated
                    // in transit (proxy retries, forked children re-flushing,
                    // infrastructure we haven't met yet), the server counts it
                    // once. Found the hard way: gbpm's canary — hard-capped at
                    // 60/hr by the minute guard — read 103.
                    'nonce' => bin2hex(random_bytes(16)),
                    'events' => $events,
                ],
            ]);
        } catch (Throwable) {
            // Swallowed silently, per the delivery NFR.
        }
    }

    /**
     * Note which model-backed streams have actually fired, so the scheduler
     * can send their history a minute later — Marmot catching up on what it
     * has just learned exists.
     *
     * Firing is the trigger deliberately: a stream that has never fired is
     * one Marmot knows nothing about, so charting its table would invent a
     * moment the app doesn't have. Waiting for the first event means only
     * real activity gets a history behind it.
     *
     * Kept cheap because this runs on the request path: off entirely unless
     * backfill is enabled, only eloquent.created streams (the sole
     * backfillable kind), and add() rather than put() so a stream already
     * noted costs one no-op write and nothing else.
     *
     * @param  array<int, array{stream: string, minute: string, count: int}>  $events
     */
    private function noteObservable(array $events): void
    {
        if (! config('marmot.backfill.automatic')) {
            return;
        }

        try {
            foreach (array_unique(array_column($events, 'stream')) as $stream) {
                if (str_starts_with($stream, 'eloquent.created: ')) {
                    Cache::add(self::OBSERVED_PREFIX.md5($stream), time(), 2_592_000);
                }
            }
        } catch (Throwable) {
            // No cache: history simply waits for the operator to run
            // marmot:backfill by hand. Never the host app's problem.
        }
    }

    private function client(): ClientInterface
    {
        return $this->client ??= new Client;
    }
}
