<?php

use App\Address\ChannelFeed;
use App\Address\GoogleScriptDirectory;

return [
    /* Addresses one user can follow. */
    'max_addresses' => 5,

    /* Seconds between polls of outages and of the list of streets. */
    'interval' => 60,
    'streets_interval' => 86400,

    /* The list of houses of a street is asked for again after this many days. */
    'houses_keep_days' => 30,

    /* The first poll reads this many days back without notifying anyone. */
    'history_days' => 3,

    /* Files processed in one cycle, attempts for each of them and the size limit in bytes. */
    'files' => ['per_cycle' => 10, 'attempts' => 6, 'max_size' => 1_000_000],

    /* News about an outage in progress older than this many hours is not sent. */
    'late_hours' => 3,

    /* An outage without any news is closed silently after this many hours past its end: announced only, confirmed. */
    'forget_hours' => ['announced' => 24, 'confirmed' => 72],

    /* Closed outages and processed posts are removed after this many days. */
    'keep_days' => 60,

    /* The admin is alerted when there has been no successful poll for this many minutes. */
    'stale_minutes' => 20,

    /* Minutes before the start of a known outage; the second reminder is taken from user settings. */
    'remind_day_minutes' => 1440,

    /* Minutes an unfinished answer about an address is waited for. */
    'draft_minutes' => 15,

    /* Classes implement App\Address\OutageFeed and App\Address\StreetDirectory. */
    'cities' => [
        'zaporizhzhia' => [
            'enabled' => true,
            'name' => 'Запоріжжя',
            'label' => 'канал ZOE_Alarm',
            'page' => 'https://t.me/zoe_alarm',
            // "files" selects files of the city by name.
            'feed' => ['class' => ChannelFeed::class, 'options' => ['channel' => 'zoe_alarm', 'files' => '/ЗМЕМ/u']],
            'directory' => [
                'class' => GoogleScriptDirectory::class,
                'options' => ['url' => 'https://script.google.com/macros/s/AKfycbwSaMDWmzsAfIT3cD8NI3p1edHEnq8STBdG3G5tl7L95R4Vhx8UjkCwRz3H_1ZqeS9R/callback'],
            ],
        ],
    ],
];
