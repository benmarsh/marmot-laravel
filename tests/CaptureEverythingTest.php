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
            'eloquent.saved: App\Models\Order',
            'eloquent.saving: App\Models\Order',
            'eloquent.creating: App\Models\Order',
            'eloquent.updating: App\Models\Order',
            'eloquent.deleting: App\Models\Order',
            'bootstrapping: Illuminate\Foundation\Bootstrap\BootProviders',
            'composing: welcome',
            'creating: welcome',
            'cache:clearing',
            'cache:cleared',
            'Illuminate\Console\Events\ArtisanStarting',
            'Illuminate\Console\Events\CommandStarting',
            'Illuminate\Console\Events\CommandFinished',
            'Illuminate\Console\Events\ScheduledTaskStarting',
            'Illuminate\Console\Events\ScheduledBackgroundTaskFinished',
            // Left default capture 19 Jul (capture-model doc): updates are
            // uninterpretable without attributes; transitions arrive named
            // via Marmot::event() instead.
            'eloquent.updated: App\Models\Subscription',
            // Request volume + its per-request shadows are plumbing.
            'Illuminate\Foundation\Http\Events\RequestHandled',
            'Illuminate\Log\Context\Events\ContextDehydrating',
            'Illuminate\Http\Client\Events\RequestSending',
            'Illuminate\Http\Client\Events\ResponseReceived',
        ];

        $captured = [
            'eloquent.created: App\Models\Order',
            'eloquent.deleted: App\Models\Order',
            'Illuminate\Auth\Events\Registered',
            'App\Events\OrderPlaced',
            // The cron dead-man's-switch trio (PRD 6.6).
            'Illuminate\Console\Events\ScheduledTaskFinished',
            'Illuminate\Console\Events\ScheduledTaskFailed',
            'Illuminate\Console\Events\ScheduledTaskSkipped',
            // Outbound reliability stays captured (individual ignores above).
            'Illuminate\Http\Client\Events\ConnectionFailed',
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
