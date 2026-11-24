<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'admin_id' => env('TELEGRAM_ADMIN_ID'),
    'timezone' => 'Europe/Kyiv',
    // Official page where users look up their queue.
    'queue_lookup_url' => 'https://www.zoe.com.ua/outage/',
    // Choices offered in the reminder settings.
    'remind_minutes' => [10, 15, 30, 60],
    // Records of the delivery log older than this many days are removed.
    'deliveries_keep_days' => max(1, (int) env('DELIVERIES_KEEP_DAYS', 30)),
    // Protection from floods: actions of one user allowed within this many seconds.
    'rate_limit' => ['actions' => 20, 'seconds' => 30],
    // Queues one user can select.
    'max_queues' => 5,
];
