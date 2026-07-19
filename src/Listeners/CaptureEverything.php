<?php

namespace Marmot\Laravel\Listeners;

use Illuminate\Support\Str;
use Marmot\Laravel\Support\EventBuffer;
use Throwable;

class CaptureEverything
{
    public function __construct(private EventBuffer $buffer)
    {
    }

    /**
     * Queue events carry the job class in the stream name, exactly as
     * eloquent events carry the model — each job class gets its own stream,
     * baseline, and flatline (a nightly import that stops is a per-job
     * dead-man's-switch). Derived SDK-side from the event object; only the
     * name and count ever ship.
     */
    private const QUEUE_STREAMS = [
        'Illuminate\Queue\Events\JobQueued' => 'queue.queued',
        'Illuminate\Queue\Events\JobProcessed' => 'queue.processed',
        'Illuminate\Queue\Events\JobFailed' => 'queue.failed',
    ];

    public function handle(string $eventName, array $payload): void
    {
        // Maintenance mode is a MARKER, not a count: artisan down/up become
        // window boundaries server-side (flatline suppression, chart shading)
        // — sent immediately, before the ignore check would swallow the
        // Foundation namespace they live in.
        if ($eventName === 'Illuminate\Foundation\Events\MaintenanceModeEnabled') {
            app(\Marmot\Laravel\Support\MarkerClient::class)->post('maintenance.started');

            return;
        }

        if ($eventName === 'Illuminate\Foundation\Events\MaintenanceModeDisabled') {
            app(\Marmot\Laravel\Support\MarkerClient::class)->post('maintenance.ended');

            return;
        }

        // A capture-mode flag, not ignore-list curation: updates are off by
        // default (uninterpretable without attributes — capture-model doc)
        // but opt-in-able where raw update volume is itself the signal.
        if (str_starts_with($eventName, 'eloquent.updated') && ! config('marmot.capture_updates', false)) {
            return;
        }

        if (Str::is(config('marmot.ignore', []), $eventName)) {
            return;
        }

        $stream = $this->named($eventName, $payload);

        if ($stream !== '') {
            $this->buffer->push($stream);
        }
    }

    private const SCHEDULE_STREAMS = [
        'Illuminate\Console\Events\ScheduledTaskFinished' => 'schedule.finished',
        'Illuminate\Console\Events\ScheduledTaskFailed' => 'schedule.failed',
        'Illuminate\Console\Events\ScheduledTaskSkipped' => 'schedule.skipped',
    ];

    /**
     * Class-named streams, eloquent-style, for every payload that carries a
     * bounded-cardinality identity: job class, notification class, scheduled
     * task, Stripe webhook type, failing host. Derivation is SDK-side; only
     * names and counts ship. Returns '' to suppress entirely.
     */
    private function named(string $eventName, array $payload): string
    {
        try {
            if (isset(self::QUEUE_STREAMS[$eventName])) {
                $job = $payload[0]->job ?? null;

                $class = match (true) {
                    ! is_object($job) => null,
                    method_exists($job, 'resolveName') => $job->resolveName(),
                    default => get_class($job),
                };

                if (is_string($class) && $class !== '') {
                    // Queued closures resolve to "Closure (file:line)" — the
                    // location is payload-shaped detail; the class is enough.
                    if (str_starts_with($class, 'Closure') || str_contains($class, 'CallQueuedClosure')) {
                        $class = 'Closure';
                    }

                    return self::QUEUE_STREAMS[$eventName].': '.$class;
                }

                return $eventName;
            }

            if (isset(self::SCHEDULE_STREAMS[$eventName])) {
                $name = $this->taskName($payload[0]->task ?? null);

                // The canary's own schedule entry would duplicate the
                // marmot.canary stream at 60/hr — suppress it.
                if ($name === 'marmot-canary') {
                    return '';
                }

                return $name === null ? $eventName : self::SCHEDULE_STREAMS[$eventName].': '.$name;
            }

            if ($eventName === 'Illuminate\Notifications\Events\NotificationSent') {
                $notification = $payload[0]->notification ?? null;

                return is_object($notification)
                    ? 'notification.sent: '.get_class($notification)
                    : $eventName;
            }

            if ($eventName === 'Laravel\Cashier\Events\WebhookReceived') {
                $type = $payload[0]->payload['type'] ?? null;

                return is_string($type) && $type !== ''
                    ? 'billing.webhook: '.$type
                    : $eventName;
            }

            if ($eventName === 'Illuminate\Http\Client\Events\ConnectionFailed') {
                $host = parse_url((string) ($payload[0]->request?->url() ?? ''), PHP_URL_HOST);

                return is_string($host) && $host !== ''
                    ? 'http.connection-failed: '.$host // Host ONLY — never path or query.
                    : $eventName;
            }
        } catch (Throwable) {
            // Fall through: an un-enrichable event still counts, unnamed.
        }

        return $eventName;
    }

    /** A scheduled task's stable identity: its name, or its artisan command. */
    private function taskName(?object $task): ?string
    {
        if ($task === null) {
            return null;
        }

        $description = $task->description ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $command = (string) ($task->command ?? '');

        // "'/usr/bin/php8.2' 'artisan' statuses:fetch > /dev/null" → the
        // command token only; binary paths and redirects are noise.
        if (preg_match("/artisan'? +([^ >]+)/", $command, $m)) {
            return $m[1];
        }

        return $command !== '' ? null : 'Closure';
    }
}
