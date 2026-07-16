<?php

namespace Marmot\Laravel;

use Illuminate\Contracts\Foundation\Application;
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

        $this->app->singleton(EventBuffer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/marmot.php' => config_path('marmot.php'),
            ], 'marmot-config');
        }

        if (! config('marmot.enabled') || ! config('marmot.api_key')) {
            return;
        }

        Event::listen('*', CaptureEverything::class);

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
