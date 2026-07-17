<?php

namespace Marmot\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use Marmot\Laravel\Support\EventBuffer;

class ProviderInertWithoutApiKeyTest extends TestCase
{
    public function test_no_events_are_captured_when_no_api_key_is_set(): void
    {
        $this->assertNull(config('marmot.api_key'));

        Event::dispatch('App\Events\OrderPlaced', []);

        $this->assertSame([], $this->app->make(EventBuffer::class)->pending());
    }

    public function test_no_canary_is_scheduled_when_inert(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $this->assertNull(collect($schedule->events())
            ->first(fn ($event) => $event->description === 'marmot-canary'));
    }

    public function test_config_merges_with_defaults(): void
    {
        $this->assertTrue(config('marmot.enabled'));
        $this->assertSame(1.0, config('marmot.timeout'));
        $this->assertContains('Illuminate\\Foundation\\Events\\*', config('marmot.ignore'));
    }
}
