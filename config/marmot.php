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
        'Illuminate\\Console\\Events\\*',
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
    ],

];
