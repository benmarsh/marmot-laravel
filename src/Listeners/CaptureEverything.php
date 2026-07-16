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
        if (Str::is(config('marmot.ignore', []), $eventName)) {
            return;
        }

        $this->buffer->push($eventName);
    }
}
