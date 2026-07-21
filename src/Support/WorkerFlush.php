<?php

namespace Marmot\Laravel\Support;

/**
 * Flush hook for long-running queue workers, which never reach the
 * terminating() or shutdown flushes a normal process gets: a supervisor
 * daemon buffered queue.processed counts forever and capture went silent
 * (uksnowmap production, 19 Jul). Listens on JobProcessed/JobFailed and
 * the worker's idle Looping tick, debounced so a busy worker pays at
 * most one POST per interval rather than one per job. The Looping tick
 * keeps firing while the queue is empty, so a burst's tail is never
 * stranded in the buffer.
 */
class WorkerFlush
{
    private float $lastFlush = 0.0;

    public function __construct(private EventBuffer $buffer, private float $interval = 15.0)
    {
    }

    public function __invoke(): void
    {
        if (microtime(true) - $this->lastFlush < $this->interval) {
            return;
        }

        $this->lastFlush = microtime(true);

        $this->buffer->flush();
    }
}
