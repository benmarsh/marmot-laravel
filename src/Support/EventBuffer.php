<?php

namespace Marmot\Laravel\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

class EventBuffer
{
    public const SDK_VERSION = '0.1.0';

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

    public function push(string $streamName): void
    {
        $key = $streamName.self::KEY_DELIMITER.gmdate('Y-m-d\TH:i:00\Z');

        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
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
                    'events' => $events,
                ],
            ]);
        } catch (Throwable) {
            // Swallowed silently, per the delivery NFR.
        }
    }

    private function client(): ClientInterface
    {
        return $this->client ??= new Client;
    }
}
