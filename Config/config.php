<?php

return [
    // Defaults for mailbox-scoped settings.
    // Values are stored in mailbox meta under the module alias.
    'defaults' => [
        'enabled' => 0,
        'mode' => 'round_robin',
        'users' => [],

        // Workflows-first (defer assignment)
        'defer_enabled' => 0,
        'defer_minutes' => 5,
        'web_fallback' => 0,

        // Sticky assignment
        'sticky_enabled' => 0,
        'sticky_days' => 60,

        // Exclusions
        'exclude_tags' => '',

        // Fallback
        'fallback_user_id' => 0,

        // Compatibility: if mailbox has a default assignee set,
        // override it with the module pool assignment.
        'override_default_assignee' => 1,

        // Diagnostics
        'audit_enabled' => 0,
    ],

    // Cron/scheduler tuning (global).
    'pending_process' => [
        // How often to process pending assignments via Laravel scheduler.
        // This runs only if a server-side cron triggers `php artisan schedule:run`.
        'every_minutes' => 1,
        // Max items to process per run.
        'limit' => 50,
    ],
];
