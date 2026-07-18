<?php

namespace Marmot\Laravel;

use Marmot\Laravel\Support\EventBuffer;
use Throwable;

/**
 * Explicit business events — the precision tier (M3 Task 9, Decision 1).
 *
 *     Marmot::event('OrderPlaced');
 *     Marmot::event('EmailsSent', 42);
 *
 * Pushes straight to the EventBuffer — deliberately NOT via Laravel's event
 * dispatcher: dispatcher routing would need a special case inside the
 * wildcard listener, re-introduce the self-ingestion loop shape M0 had to
 * break, and add a round-trip for no benefit. Downstream, an explicit event
 * is indistinguishable from a captured one — and its name survives the model
 * renames that silently break an "eloquent.created:" stream's identity.
 */
class Marmot
{
    public static function event(string $name, int $count = 1): void
    {
        try {
            if (! config('marmot.enabled') || ! config('marmot.api_key')) {
                return;
            }

            $name = trim($name);

            if ($name === '' || strlen($name) > 255 || $count < 1) {
                return; // Telemetry never throws (the delivery NFR).
            }

            app(EventBuffer::class)->push($name, $count);
        } catch (Throwable) {
            // Never the host app's problem.
        }
    }
}
