<?php

namespace Marmot\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Marmot\Laravel\Listeners\CaptureEverything;
use Marmot\Laravel\Support\EventBuffer;
use Throwable;

class MarmotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/marmot.php', 'marmot');

        // Explicit construction — NEVER let the container auto-inject
        // Guzzle's ClientInterface. Host apps bind it for their own purposes
        // (laravel-openrouter binds a client with retry-on-timeout ×5 and
        // attribution headers), and auto-injection quietly routed every
        // flush through it: each >1s ingest response became up to six
        // deliveries of the same batch. Tests inject via 'marmot.http_client'.
        $this->app->singleton(EventBuffer::class, function ($app) {
            return new EventBuffer(
                $app->bound('marmot.http_client') ? $app->make('marmot.http_client') : null,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/marmot.php' => config_path('marmot.php'),
            ], 'marmot-config');

            // Registered before the enabled-guard: an unconfigured install
            // gets a helpful error from the command, not "command not found".
            $this->commands([Console\BackfillCommand::class, Console\DeployCommand::class]);
        }

        if (! config('marmot.enabled') || ! config('marmot.api_key')) {
            return;
        }

        Event::listen('*', CaptureEverything::class);

        // Canary heartbeat: fires every minute wherever the host app's
        // scheduler runs, giving Marmot a stream that proves the whole
        // pipeline (host cron -> SDK -> ingest) is alive. Watch it with a
        // tight flatline threshold server-side.
        if (config('marmot.canary', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->call(function () {
                    // At most one fire per clock minute: extra schedule
                    // executions in the same minute (manual schedule:run,
                    // stacked delayed crons, a second server) must not
                    // inflate the heartbeat — a 60/hr canary that can read
                    // 87 corrupts every baseline built on it.
                    try {
                        $fire = Cache::add('marmot:canary:'.gmdate('YmdHi'), true, 120);
                    } catch (Throwable) {
                        // Cache unavailable: firing twice beats never firing.
                        $fire = true;
                    }

                    if ($fire) {
                        event('marmot.canary');
                    }
                })
                    ->cron(config('marmot.canary_cron', '* * * * *'))
                    ->name('marmot-canary');
            });
        }

        $this->app->terminating(fn () => $this->app->make(EventBuffer::class)->flush());

        // Console contexts (artisan commands, seeders, imports) never reach
        // terminating() — a shutdown hook is the backstop. flush() is a no-op
        // on an empty buffer, so double-flushing is safe.
        register_shutdown_function(function (Application $app) {
            try {
                $app->make(EventBuffer::class)->flush();
            } catch (Throwable) {
                // Never let telemetry surface during shutdown.
            }
        }, $this->app);
    }
}
