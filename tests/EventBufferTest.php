<?php

namespace Marmot\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Marmot\Laravel\Support\EventBuffer;

class EventBufferTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    private array $history = [];

    private function mockedBuffer(MockHandler $handler): EventBuffer
    {
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));

        return new EventBuffer(new Client(['handler' => $stack]));
    }

    public function test_ten_thousand_pushes_across_three_streams_aggregate_to_three_entries(): void
    {
        $buffer = new EventBuffer;

        $streams = ['OrderPlaced', 'eloquent.created: App\\Models\\User', 'PaymentReceived'];

        for ($i = 0; $i < 10_000; $i++) {
            $buffer->push($streams[$i % 3]);
        }

        $pending = $buffer->pending();

        $this->assertCount(3, $pending);
        $this->assertSame(10_000, array_sum(array_column($pending, 'count')));
    }

    public function test_flush_posts_expected_payload_and_clears_buffer(): void
    {
        config(['marmot.endpoint' => 'https://ingest.marmot.test/v1/events', 'marmot.api_key' => 'test-key']);

        $buffer = $this->mockedBuffer(new MockHandler([new Response(200)]));

        $buffer->push('OrderPlaced');
        $buffer->push('OrderPlaced');
        $buffer->flush();

        $this->assertCount(1, $this->history);

        $request = $this->history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('laravel', $body['source']);
        $this->assertSame(EventBuffer::SDK_VERSION, $body['sdk_version']);
        $this->assertCount(1, $body['events']);
        $this->assertSame('OrderPlaced', $body['events'][0]['stream']);
        $this->assertSame(2, $body['events'][0]['count']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/', $body['events'][0]['minute']);

        $this->assertSame([], $buffer->pending());
    }

    public function test_flush_swallows_failures_and_still_clears_buffer(): void
    {
        config(['marmot.endpoint' => 'https://ingest.marmot.test/v1/events', 'marmot.api_key' => 'test-key']);

        $buffer = $this->mockedBuffer(new MockHandler([
            new ConnectException('refused', new Request('POST', 'https://ingest.marmot.test/v1/events')),
        ]));

        $buffer->push('OrderPlaced');
        $buffer->flush();

        $this->assertSame([], $buffer->pending());
    }

    public function test_flush_is_a_noop_on_an_empty_buffer(): void
    {
        config(['marmot.endpoint' => 'https://ingest.marmot.test/v1/events', 'marmot.api_key' => 'test-key']);

        $buffer = $this->mockedBuffer(new MockHandler([new Response(200)]));

        $buffer->flush();
        $buffer->flush();

        $this->assertCount(0, $this->history);
    }

    public function test_flush_without_endpoint_makes_no_request(): void
    {
        config(['marmot.endpoint' => null]);

        $buffer = $this->mockedBuffer(new MockHandler([new Response(200)]));

        $buffer->push('OrderPlaced');
        $buffer->flush();

        $this->assertCount(0, $this->history);
        $this->assertSame([], $buffer->pending());
    }
}
