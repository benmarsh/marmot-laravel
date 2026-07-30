<?php

namespace Marmot\Laravel\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Marmot's own HTTP client — the one isolation rule, in one place.
 *
 * NEVER resolve the container's ClientInterface. Host apps bind it for their
 * own purposes (laravel-openrouter binds one with retry-on-timeout ×5), and
 * auto-injection once quietly turned every slow ingest response into up to
 * six deliveries of the same batch — the gbpm double-delivery incident.
 *
 * 'marmot.http_client' is the test seam and the ONLY binding we honour.
 */
class HttpClient
{
    public static function make(): ClientInterface
    {
        return app()->bound('marmot.http_client')
            ? app()->make('marmot.http_client')
            : new Client;
    }
}
