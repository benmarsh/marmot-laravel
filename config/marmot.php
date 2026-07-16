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
        'Illuminate\\Log\\Events\\*',
        'Illuminate\\Console\\Events\\*',
        'Illuminate\\Session\\*',
        'Illuminate\\View\\*',
        'eloquent.booting*',
        'eloquent.booted*',
        'eloquent.retrieved*',
        'bootstrapping*',
        'bootstrapped*',
        'composing*',
    ],

];
