<?php

namespace Marmot\Laravel\Tests;

use Marmot\Laravel\Marmot;
use Marmot\Laravel\Support\EventBuffer;

class MarmotEventTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
        $app['config']->set('marmot.endpoint', 'https://marmot.test/v1/events');
    }

    public function test_explicit_events_land_in_the_buffer_like_captured_ones(): void
    {
        Marmot::event('OrderPlaced');
        Marmot::event('OrderPlaced');
        Marmot::event('EmailsSent', 42);

        $pending = $this->app->make(EventBuffer::class)->pending();

        $this->assertCount(2, $pending);
        $this->assertSame('OrderPlaced', $pending[0]['stream']);
        $this->assertSame(2, $pending[0]['count']);
        $this->assertSame('EmailsSent', $pending[1]['stream']);
        $this->assertSame(42, $pending[1]['count']);
        // Same {stream, minute, count} shape a captured event produces.
        $this->assertArrayHasKey('minute', $pending[0]);
    }

    public function test_invalid_input_is_silently_ignored(): void
    {
        Marmot::event('');
        Marmot::event('   ');
        Marmot::event(str_repeat('x', 256));
        Marmot::event('Fine', 0);
        Marmot::event('Fine', -5);

        $this->assertSame([], $this->app->make(EventBuffer::class)->pending());
    }

    public function test_disabled_or_keyless_installs_are_inert(): void
    {
        config(['marmot.enabled' => false]);
        Marmot::event('OrderPlaced');

        config(['marmot.enabled' => true, 'marmot.api_key' => null]);
        Marmot::event('OrderPlaced');

        $this->assertSame([], $this->app->make(EventBuffer::class)->pending());
    }
}
