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
        // A capture-mode flag, not ignore-list curation: updates are off by
        // default (uninterpretable without attributes — capture-model doc)
        // but opt-in-able where raw update volume is itself the signal.
        if (str_starts_with($eventName, 'eloquent.updated') && ! config('marmot.capture_updates', false)) {
            return;
        }

        if (Str::is(config('marmot.ignore', []), $eventName)) {
            return;
        }

        $this->buffer->push($this->named($eventName, $payload));
    }

    private function named(string $eventName, array $payload): string
    {
        if (! isset(self::QUEUE_STREAMS[$eventName])) {
            return $eventName;
        }

        try {
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
        } catch (Throwable) {
            // Fall through: an un-enrichable job still counts, unnamed.
        }

        return $eventName;
    }
}
