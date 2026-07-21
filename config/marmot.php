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
    | Worker flush interval (seconds)
    |--------------------------------------------------------------------------
    | Long-running queue workers flush the buffer between jobs and on idle
    | loops, at most once per this many seconds. Ingest buckets counts per
    | minute, so sub-minute delivery is plenty; raising this trades
    | freshness for fewer requests from very busy workers.
    */

    'worker_flush_seconds' => 15,

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
        // Request volume is plumbing/proxy, not business signal (capture-model
        // doc, Tier 3) — and self-monitoring apps must never capture it
        // (flush → request → flush self-ingestion).
        'Illuminate\\Foundation\\Http\\Events\\RequestHandled',
        'Illuminate\\Routing\\Events\\*',
        // Log-context lifecycle fires per request purely because context
        // exists — a worse duplicate of request volume.
        'Illuminate\\Log\\Context\\Events\\*',
        // HTTP client internals ignored individually, not by namespace:
        // ConnectionFailed stays captured (outbound reliability signal).
        'Illuminate\\Http\\Client\\Events\\RequestSending',
        'Illuminate\\Http\\Client\\Events\\ResponseReceived',
        // Queue mechanics, transitional-pairs rule: JobQueued (demand),
        // JobProcessed (throughput + worker-death dead-man's-switch) and
        // JobFailed (terminal failures) stay; the rest is worker plumbing.
        'Illuminate\\Queue\\Events\\JobQueueing',
        'Illuminate\\Queue\\Events\\JobPopping',
        'Illuminate\\Queue\\Events\\JobPopped',
        'Illuminate\\Queue\\Events\\JobProcessing',
        'Illuminate\\Queue\\Events\\JobExceptionOccurred',
        'Illuminate\\Queue\\Events\\JobReleasedAfterException',
        'Illuminate\\Queue\\Events\\Looping',
        'Illuminate\\Queue\\Events\\WorkerStopping',
        'Illuminate\\Queue\\Events\\QueueBusy',
        // Auth plumbing: Attempting/Validated are Login's transitional pair;
        // Authenticated and Sanctum's TokenAuthenticated fire per request —
        // request-volume shadows. Login/Logout/Registered/Failed/Lockout
        // stay captured.
        'Illuminate\\Auth\\Events\\Attempting',
        'Illuminate\\Auth\\Events\\Validated',
        'Illuminate\\Auth\\Events\\Authenticated',
        'Laravel\\Sanctum\\Events\\TokenAuthenticated',
        // Transitional pairs of MessageSent / NotificationSent.
        'Illuminate\\Mail\\Events\\MessageSending',
        'Illuminate\\Notifications\\Events\\NotificationSending',
        // Admin-panel UI plumbing (the host app's own dashboards).
        'Filament\\*',
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
        // Vendor lifecycle events that shadow signals we already capture.
        // Media added/cleared duplicate the Media model's created/deleted
        // rows 1:1 (model discovery keeps vendor models); backup lifecycle
        // (DumpingDatabase, ManifestWasCreated…) shadows the backup:run
        // schedule streams, which are the real cron dead-man's-switch —
        // and failures still surface via schedule.failed + the failure
        // notification.
        'Spatie\\MediaLibrary\\MediaCollections\\Events\\*',
        'Spatie\\Backup\\Events\\*',
        'eloquent.booting*',
        'eloquent.booted*',
        'eloquent.retrieved*',
        // Transitional lifecycle events pair 1:1 with their past-tense
        // counterparts (saving/saved, creating/created…) — keeping both
        // doubles stream count and produces duplicate alerts.
        // `saved` is redundant with created/updated EXCEPT for no-change
        // saves (save() on a clean model fires saved, nothing else) — and
        // those are noise: an import re-saving unchanged rows produced a
        // steady 56/hr phantom stream in production.
        // (`updated` is gated by the capture_updates flag below, not listed
        // here — it's a capture-mode choice, not enumerable noise.)
        'eloquent.saved*',
        'eloquent.saving*',
        'eloquent.creating*',
        'eloquent.updating*',
        'eloquent.deleting*',
        'eloquent.restoring*',
        // Soft-delete qualifiers duplicate `deleted` (a soft delete fires
        // deleted AND trashed; a force delete fires deleted AND
        // forceDeleted) — `deleted` alone is the existence-change signal.
        'eloquent.trashed*',
        'eloquent.forceDeleted*',
        'eloquent.forceDeleting*',
        'bootstrapping*',
        'bootstrapped*',
        'composing*',
        'creating:*',
        // Deploy-time artisan plumbing (cache:clearing / cache:cleared).
        'cache:*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture eloquent.updated events
    |--------------------------------------------------------------------------
    | OFF by default (19 Jul capture-model decision): an update event without
    | attributes is uninterpretable — "onboarding completed" and "changed
    | avatar" are the same stream — and the transitions that matter belong in
    | named Marmot::event() calls. Opt in per app when raw update VOLUME is
    | itself the signal you want (e.g. an import's content-freshness pulse),
    | typically as a bridge until explicit events replace it.
    */

    'capture_updates' => env('MARMOT_CAPTURE_UPDATES', false),

];
