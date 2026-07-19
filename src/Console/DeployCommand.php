<?php

namespace Marmot\Laravel\Console;

use Illuminate\Console\Command;
use Marmot\Laravel\Support\MarkerClient;
use Throwable;

/**
 * Deploy marker (M3 Task 2). Call from the deploy script (Forge: add
 * `php artisan marmot:deploy` after composer install) — or let the composer
 * post-install hook do it. The marker lands in the activity feed, draws on
 * detail charts, and stamps context onto alerts that fire soon after.
 */
class DeployCommand extends Command
{
    protected $signature = 'marmot:deploy {description? : Optional label for this deploy}';

    protected $description = 'Send a deploy marker to Marmot';

    public function handle(MarkerClient $markers): int
    {
        $payload = array_filter([
            'description' => $this->argument('description'),
            'sha' => $this->sha(),
        ]);

        if ($markers->post('deploy', $payload)) {
            $this->info('Deploy marker sent'.(isset($payload['sha']) ? " ({$payload['sha']})" : '').'.');
        } else {
            // Not an error state for the deploy pipeline: unconfigured or
            // unreachable Marmot must never fail a deploy.
            $this->line('Marmot not configured or unreachable — marker skipped.');
        }

        return self::SUCCESS;
    }

    private function sha(): ?string
    {
        try {
            $sha = trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null'));

            return preg_match('/^[0-9a-f]{6,40}$/', $sha) ? $sha : null;
        } catch (Throwable) {
            return null;
        }
    }
}
