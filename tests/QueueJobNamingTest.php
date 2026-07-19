<?php

namespace Marmot\Laravel\Tests;

use Marmot\Laravel\Listeners\CaptureEverything;
use Marmot\Laravel\Support\EventBuffer;

class FakeQueueJob
{
    public function resolveName(): string
    {
        return 'App\Jobs\FetchGfsRun';
    }
}

class FakeJobEvent
{
    public function __construct(public object $job)
    {
    }
}

class FakeClosureJob
{
    public function resolveName(): string
    {
        return 'Closure (routes/console.php:41)';
    }
}

class QueueJobNamingTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
    }

    public function test_queue_events_carry_the_job_class_like_eloquent_carries_the_model(): void
    {
        $buffer = new EventBuffer;
        $listener = new CaptureEverything($buffer);

        $event = new FakeJobEvent(new FakeQueueJob);

        $listener->handle('Illuminate\Queue\Events\JobProcessed', [$event]);
        $listener->handle('Illuminate\Queue\Events\JobFailed', [$event]);
        $listener->handle('Illuminate\Queue\Events\JobQueued', [$event]);

        $streams = array_column($buffer->pending(), 'stream');
        sort($streams);

        $this->assertSame([
            'queue.failed: App\Jobs\FetchGfsRun',
            'queue.processed: App\Jobs\FetchGfsRun',
            'queue.queued: App\Jobs\FetchGfsRun',
        ], $streams);
    }

    public function test_closures_collapse_to_a_single_stream_without_file_locations(): void
    {
        $buffer = new EventBuffer;
        $listener = new CaptureEverything($buffer);

        $listener->handle('Illuminate\Queue\Events\JobProcessed', [new FakeJobEvent(new FakeClosureJob)]);

        $this->assertSame('queue.processed: Closure', $buffer->pending()[0]['stream']);
    }

    public function test_unenrichable_payloads_fall_back_to_the_raw_event_stream(): void
    {
        $buffer = new EventBuffer;
        $listener = new CaptureEverything($buffer);

        $listener->handle('Illuminate\Queue\Events\JobProcessed', []);

        $this->assertSame('Illuminate\Queue\Events\JobProcessed', $buffer->pending()[0]['stream']);
    }
}
