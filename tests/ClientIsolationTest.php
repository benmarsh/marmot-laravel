<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Marmot\Laravel\Support\EventBuffer;

class ClientIsolationTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
        $app['config']->set('marmot.endpoint', 'https://marmot.test/v1/events');
    }

    /**
     * The gbpm production incident, as a regression test: a host-app package
     * (laravel-openrouter) bound ClientInterface container-wide to a client
     * with retry-on-timeout ×5 — and auto-injection routed marmot's flushes
     * through it, delivering each batch up to six times. The SDK must ignore
     * that binding entirely.
     */
    public function test_event_buffer_ignores_host_app_client_interface_bindings(): void
    {
        $hostAppClient = new Client(['headers' => ['HTTP-Referer' => 'https://host-app.example']]);

        $this->app->instance(ClientInterface::class, $hostAppClient);

        $buffer = $this->app->make(EventBuffer::class);

        $reflection = new \ReflectionProperty($buffer, 'client');

        $this->assertNotSame($hostAppClient, $reflection->getValue($buffer));
    }

    public function test_marmot_http_client_seam_still_injects(): void
    {
        $seamClient = new Client;

        $this->app->instance('marmot.http_client', $seamClient);

        $buffer = $this->app->make(EventBuffer::class);

        $reflection = new \ReflectionProperty($buffer, 'client');

        $this->assertSame($seamClient, $reflection->getValue($buffer));
    }
}
