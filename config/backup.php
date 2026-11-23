<?php

return [
    /* Directory for daily database copies; empty = backups are off. */
    'path' => env('BACKUP_PATH'),

    /* Copies older than this many days are removed. */
    'keep_days' => max(1, (int) env('BACKUP_KEEP_DAYS', 14)),

    /* The newest copies that are never removed, even when backups stop working. */
    'keep_min' => 3,

    /* Local time (Europe/Kyiv) after which the copy of the day is made. */
    'time' => '03:30',

    /* Pause before another attempt after a failure. */
    'retry_minutes' => 60,
];
