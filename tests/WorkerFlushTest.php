<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Queue\Events\Looping;
use Marmot\Laravel\Support\EventBuffer;
use Marmot\Laravel\Support\WorkerFlush;

class WorkerFlushTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
        $app['config']->set('marmot.endpoint', 'https://marmot.test/v1/events');

        $history = Middleware::history($this->requests);
        $mock = new MockHandler(array_fill(0, 10, new Response(202)));
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $app->instance('marmot.http_client', new Client(['handler' => $stack]));
    }

    public function test_worker_loop_flushes_buffered_counts(): void
    {
        $this->app->make(EventBuffer::class)->push('queue.processed: App\Jobs\ImportJob');

        // The idle tick a daemonised queue:work fires between jobs — the
        // only flush opportunity a never-terminating process gets.
        event(new Looping('default', 'default'));

        $this->assertCount(1, $this->requests);

        $body = json_decode((string) $this->requests[0]['request']->getBody(), true);

        $this->assertSame('queue.processed: App\Jobs\ImportJob', $body['events'][0]['stream']);
    }

    public function test_flushes_are_debounced_within_the_interval(): void
    {
        $buffer = $this->app->make(EventBuffer::class);
        $flush = new WorkerFlush($buffer, 3600.0);

        $buffer->push('queue.processed: App\Jobs\ImportJob');
        $flush();

        $buffer->push('queue.processed: App\Jobs\ImportJob');
        $flush();

        // Second call inside the interval: buffered, not sent.
        $this->assertCount(1, $this->requests);
        $this->assertCount(1, $buffer->pending());
    }

    public function test_interval_zero_flushes_every_time(): void
    {
        $buffer = $this->app->make(EventBuffer::class);
        $flush = new WorkerFlush($buffer, 0.0);

        $buffer->push('a');
        $flush();
        $buffer->push('b');
        $flush();

        $this->assertCount(2, $this->requests);
    }

    public function test_empty_buffer_costs_nothing(): void
    {
        event(new Looping('default', 'default'));

        $this->assertCount(0, $this->requests);
    }
}
