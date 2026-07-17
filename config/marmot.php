<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Master switch. When false (or when no API key is set) Marmot registers
    | no listeners and makes no network calls — fully inert.
    */

    'enabled' => env('MARMOT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | API key & endpoint
    |--------------------------------------------------------------------------
    */

    'api_key' => env('MARMOT_API_KEY'),

    'endpoint' => env('MARMOT_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Outbound timeout (seconds)
    |--------------------------------------------------------------------------
    | If Marmot's ingest is slow or down, that must never become the host
    | app's problem.
    */

    'timeout' => 1.0,

    /*
    |--------------------------------------------------------------------------
    | Canary heartbeat
    |--------------------------------------------------------------------------
    | When enabled (and the SDK is active), a marmot.canary event fires every
    | minute via the host app's scheduler — a heartbeat proving the whole
    | pipeline is alive. Costs one tiny counter per minute.
    */

    'canary' => env('MARMOT_CANARY', true),

    /*
    |--------------------------------------------------------------------------
    | Canary cron expression
    |--------------------------------------------------------------------------
    | How often the canary fires. Every minute unless you are deliberately
    | degrading it (e.g. Marmot's own induced-dip detection test).
    */

    'canary_cron' => env('MARMOT_CANARY_CRON', '* * * * *'),

    /*
    |--------------------------------------------------------------------------
    | Ignored event patterns
    |--------------------------------------------------------------------------
    | Framework plumbing only. Eloquent lifecycle events (created/updated/
    | deleted) are deliberately NOT ignored — automatic model discovery is
    | the point.
    */

    'ignore' => [
        'Illuminate\\Foundation\\Events\\*',
        'Illuminate\\Routing\\Events\\*',
        'Illuminate\\Cache\\Events\\*',
        // Raw query/connection volume is plumbing, not business signal.
        // Slow-query detection (PRD 6.6) will be payload-based, not name-based.
        'Illuminate\\Database\\Events\\*',
        'Illuminate\\Log\\Events\\*',
        // Console events are ignored individually, not by namespace:
        // ScheduledTask{Finished,Failed,Skipped} must stay captured — they
        // are the cron dead-man's-switch (PRD 6.6) and free baseline signal.
        'Illuminate\\Console\\Events\\ArtisanStarting',
        'Illuminate\\Console\\Events\\CommandStarting',
        'Illuminate\\Console\\Events\\CommandFinished',
        'Illuminate\\Console\\Events\\ScheduledTaskStarting',
        'Illuminate\\Console\\Events\\ScheduledBackgroundTaskFinished',
        'Illuminate\\Session\\*',
        'Illuminate\\View\\*',
        'eloquent.booting*',
        'eloquent.booted*',
        'eloquent.retrieved*',
        // Transitional lifecycle events pair 1:1 with their past-tense
        // counterparts (saving/saved, creating/created…) — keeping both
        // doubles stream count and produces duplicate alerts.
        'eloquent.saving*',
        'eloquent.creating*',
        'eloquent.updating*',
        'eloquent.deleting*',
        'eloquent.restoring*',
        'bootstrapping*',
        'bootstrapped*',
        'composing*',
        'creating:*',
        // Deploy-time artisan plumbing (cache:clearing / cache:cleared).
        'cache:*',
    ],

];
