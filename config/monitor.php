<?php

return [
    /* Directory where processes report their state; empty = monitoring is off. */
    'path' => env('MONITOR_PATH'),

    /* Processes that must be alive. */
    'services' => ['worker', 'reminders', 'bot'],

    /* Seconds between checks. */
    'interval' => 60,

    /* Seconds before the same problem is reported again. */
    'repeat' => 3600,

    /* Local hours (Europe/Kyiv) when only critical problems are reported with sound. */
    'quiet' => ['from' => '23:00', 'to' => '07:00'],

    /* Free disk space in megabytes. */
    'disk' => 2048,
    'disk_critical' => 500,

    /* Available memory in megabytes and load average; both must last this many seconds. */
    'memory' => 100,
    'load' => 2.0,
    'sustained' => 300,

    /* Replies are late: seconds a message has waited, or updates received in one request. */
    'reply_lag' => 10,
    'reply_backlog' => 30,

    /* Seconds a queued message may wait to be sent. */
    'delivery_lag' => 300,

    /* Within the last 5 minutes: more errors, at least this many database errors, more users over the limit. */
    'errors' => 10,
    'database_errors' => 3,
    'flood_users' => 5,

    /* Seconds without a sign of life from a process and from the monitor itself. */
    'hang' => 120,
    'monitor_hang' => 300,

    /* This many starts of a process within 10 minutes. */
    'starts' => 3,
];
