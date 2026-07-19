<?php

namespace Marmot\Laravel\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Point-in-time markers (deploys, maintenance windows) — sent immediately,
 * not buffered: a marker's value is its timestamp, and deploy processes exit
 * quickly. Same delivery NFR as events: failures are swallowed, the host
 * app never notices.
 */
class MarkerClient
{
    private ?ClientInterface $client = null;

    public function post(string $type, array $payload = []): bool
    {
        try {
            if (! config('marmot.enabled') || ! config('marmot.api_key')) {
                return false;
            }

            $endpoint = preg_replace('#/v1/events$#', '/v1/deploys', (string) config('marmot.endpoint'));

            if (! $endpoint) {
                return false;
            }

            $response = $this->client()->request('POST', $endpoint, [
                'timeout' => 3,
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer '.config('marmot.api_key'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => ['type' => $type] + $payload,
            ]);

            return $response->getStatusCode() === 200;
        } catch (Throwable) {
            return false;
        }
    }

    private function client(): ClientInterface
    {
        // Same isolation rule as EventBuffer: never the host app's client.
        return $this->client ??= app()->bound('marmot.http_client')
            ? app('marmot.http_client')
            : new Client;
    }
}
