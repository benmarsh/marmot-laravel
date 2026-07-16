<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Marmot\Laravel\Support\EventBuffer;

class ProviderCapturesAndFlushesTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
    }

    public function test_wildcard_listener_captures_events_into_the_buffer(): void
    {
        Event::dispatch('eloquent.created: App\Models\Order', []);
        Event::dispatch('eloquent.created: App\Models\Order', []);
        Event::dispatch('Illuminate\Foundation\Events\LocaleUpdated', []);

        $pending = $this->app->make(EventBuffer::class)->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('eloquent.created: App\Models\Order', $pending[0]['stream']);
        $this->assertSame(2, $pending[0]['count']);

        $this->app->make(EventBuffer::class)->flush(); // endpoint unset: clears without a request
    }

    public function test_terminating_flushes_the_buffer_to_the_endpoint(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push(Middleware::history($history));

        $this->app->instance(EventBuffer::class, new EventBuffer(new Client(['handler' => $stack])));

        config(['marmot.endpoint' => 'https://ingest.marmot.test/v1/events']);

        Event::dispatch('App\Events\OrderPlaced', []);

        $this->app->terminate();

        $this->assertCount(1, $history);

        $body = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertSame('App\Events\OrderPlaced', $body['events'][0]['stream']);
        $this->assertSame([], $this->app->make(EventBuffer::class)->pending());
    }
}
