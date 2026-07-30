<?php

namespace Marmot\Laravel\Console;

use Illuminate\Console\Command;
use Marmot\Laravel\Jobs\RunBackfillInstruction;
use Marmot\Laravel\Support\InstructionHandler;

/**
 * Scheduler fallback for the control channel (build brief §4b: "prefer
 * queue, fall back to scheduler").
 *
 * An app with no real queue connection can't dispatch work without running
 * it inline — and inline, from a flush, means inside a web request, which
 * the never-slow-the-app NFR forbids. Those apps park the instruction
 * instead; this command runs what's parked, from the scheduler, where a
 * long historical scan costs the host app's users nothing.
 *
 * Harmless to run on an app that does have a queue: nothing gets parked
 * there, so there's nothing to drain.
 */
class ProcessInstructionsCommand extends Command
{
    protected $signature = 'marmot:process-instructions';

    protected $description = 'Run backfill work Marmot has queued for this app (scheduler fallback)';

    public function handle(): int
    {
        $parked = InstructionHandler::drainParked();

        if ($parked === []) {
            $this->info('Nothing queued.');

            return self::SUCCESS;
        }

        foreach ($parked as $id => $work) {
            $this->info("Backfilling {$work['stream']} ({$work['weeks']} weeks)…");

            // Sequential by construction — one instruction at a time limits
            // load on the source database (§4c).
            (new RunBackfillInstruction($id, $work['stream'], $work['weeks']))->handle();
        }

        $this->info('Done: '.count($parked).' instruction(s).');

        return self::SUCCESS;
    }
}
