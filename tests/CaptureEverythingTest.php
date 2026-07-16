<?php

namespace Marmot\Laravel\Tests;

use Marmot\Laravel\Listeners\CaptureEverything;
use Marmot\Laravel\Support\EventBuffer;

class CaptureEverythingTest extends TestCase
{
    public function test_only_non_ignored_events_reach_the_buffer(): void
    {
        $buffer = new EventBuffer;
        $listener = new CaptureEverything($buffer);

        $ignored = [
            'Illuminate\Foundation\Events\LocaleUpdated',
            'Illuminate\Routing\Events\RouteMatched',
            'Illuminate\Cache\Events\CacheHit',
            'Illuminate\Database\Events\QueryExecuted',
            'Illuminate\Database\Events\ConnectionEstablished',
            'eloquent.booting: App\Models\Order',
            'eloquent.retrieved: App\Models\Order',
            'bootstrapping: Illuminate\Foundation\Bootstrap\BootProviders',
            'composing: welcome',
            'creating: welcome',
        ];

        $captured = [
            'eloquent.created: App\Models\Order',
            'eloquent.updated: App\Models\Subscription',
            'Illuminate\Auth\Events\Registered',
            'App\Events\OrderPlaced',
        ];

        foreach ([...$ignored, ...$captured] as $eventName) {
            $listener->handle($eventName, []);
        }

        $streams = array_column($buffer->pending(), 'stream');

        sort($streams);
        sort($captured);

        $this->assertSame($captured, $streams);
    }
}
