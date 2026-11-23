<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'admin_id' => env('TELEGRAM_ADMIN_ID'),
    'timezone' => 'Europe/Kyiv',
    // Official page where users look up their queue.
    'queue_lookup_url' => 'https://www.zoe.com.ua/outage/',
    // Choices offered in the reminder settings.
    'remind_minutes' => [10, 15, 30, 60],
];
