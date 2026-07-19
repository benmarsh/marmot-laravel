<?php

namespace Marmot\Laravel\Tests;

use Marmot\Laravel\Listeners\CaptureEverything;
use Marmot\Laravel\Support\EventBuffer;

class FakeTask
{
    public function __construct(public ?string $description = null, public string $command = '')
    {
    }
}

class FakeTaskEvent
{
    public function __construct(public object $task)
    {
    }
}

class FakeNotification
{
}

class FakeNotificationEvent
{
    public object $notification;

    public function __construct()
    {
        $this->notification = new FakeNotification;
    }
}

class FakeWebhookEvent
{
    public array $payload = ['type' => 'invoice.paid'];
}

class FakeHttpRequest
{
    public function url(): string
    {
        return 'https://graph.facebook.com/v19.0/feed?access_token=secret';
    }
}

class FakeConnectionFailedEvent
{
    public object $request;

    public function __construct()
    {
        $this->request = new FakeHttpRequest;
    }
}

class ClassNamedStreamsTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('marmot.api_key', 'test-key');
    }

    private function capture(string $event, array $payload): array
    {
        $buffer = new EventBuffer;
        (new CaptureEverything($buffer))->handle($event, $payload);

        return array_column($buffer->pending(), 'stream');
    }

    public function test_scheduled_tasks_split_per_task(): void
    {
        $named = $this->capture('Illuminate\Console\Events\ScheduledTaskFinished',
            [new FakeTaskEvent(new FakeTask(null, "'/usr/bin/php8.2' 'artisan' statuses:fetch > '/dev/null' 2>&1"))]);

        $this->assertSame(['schedule.finished: statuses:fetch'], $named);

        $described = $this->capture('Illuminate\Console\Events\ScheduledTaskFailed',
            [new FakeTaskEvent(new FakeTask('nightly-backup'))]);

        $this->assertSame(['schedule.failed: nightly-backup'], $described);
    }

    public function test_the_canary_task_is_suppressed_not_duplicated(): void
    {
        $streams = $this->capture('Illuminate\Console\Events\ScheduledTaskFinished',
            [new FakeTaskEvent(new FakeTask('marmot-canary'))]);

        $this->assertSame([], $streams);
    }

    public function test_notifications_split_per_class(): void
    {
        $streams = $this->capture('Illuminate\Notifications\Events\NotificationSent', [new FakeNotificationEvent]);

        $this->assertSame(['notification.sent: Marmot\Laravel\Tests\FakeNotification'], $streams);
    }

    public function test_cashier_webhooks_split_per_stripe_event_type(): void
    {
        $streams = $this->capture('Laravel\Cashier\Events\WebhookReceived', [new FakeWebhookEvent]);

        $this->assertSame(['billing.webhook: invoice.paid'], $streams);
    }

    public function test_connection_failures_split_per_host_only(): void
    {
        $streams = $this->capture('Illuminate\Http\Client\Events\ConnectionFailed', [new FakeConnectionFailedEvent]);

        // Host only: no path, no query, no tokens.
        $this->assertSame(['http.connection-failed: graph.facebook.com'], $streams);
    }

    public function test_unenrichable_events_fall_back_to_raw_streams(): void
    {
        $this->assertSame(['Illuminate\Console\Events\ScheduledTaskFinished'],
            $this->capture('Illuminate\Console\Events\ScheduledTaskFinished', []));

        $this->assertSame(['Laravel\Cashier\Events\WebhookReceived'],
            $this->capture('Laravel\Cashier\Events\WebhookReceived', []));
    }
}
