<?php

namespace Marmot\Laravel\Listeners;

use Illuminate\Support\Str;
use Marmot\Laravel\Support\EventBuffer;

class CaptureEverything
{
    public function __construct(private EventBuffer $buffer)
    {
    }

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

        $this->buffer->push($eventName);
    }
}
