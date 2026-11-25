<?php

use App\Address\GoogleScriptSource;

return [
    /* Addresses one user can follow. */
    'max_addresses' => 3,

    /* Seconds between polls of outages and of the list of streets. */
    'interval' => 120,
    'streets_interval' => 86400,

    /* The list of houses of a street is asked for again after this many days. */
    'houses_keep_days' => 30,

    /* An outage is over when it is missing from this many successful responses in a row. */
    'missing_polls' => 2,

    /*
     * A response with less than this share of the previous rows does not end outages
     * until it has been repeated this many times. Lists shorter than "from" are not checked.
     */
    'shrink' => ['share' => 0.5, 'from' => 20, 'polls' => 15],

    /* Scheduled works that leave the list later than this many hours after their end are closed without a message. */
    'silent_end_hours' => 3,

    /* Ended outages of users are removed after this many days. */
    'keep_days' => 30,

    /* The admin is alerted when there has been no successful response for this many minutes. */
    'stale_minutes' => 20,

    /* Minutes before the start of a known outage; the second reminder is taken from user settings. */
    'remind_day_minutes' => 1440,

    /* Minutes an unfinished answer about an address is waited for. */
    'draft_minutes' => 15,

    /* The class implements App\Address\CitySource. */
    'cities' => [
        'zaporizhzhia' => [
            'enabled' => true,
            'name' => 'Запоріжжя',
            'label' => 'сторінка Запоріжжяобленерго',
            'page' => 'https://script.google.com/macros/s/AKfycbwSaMDWmzsAfIT3cD8NI3p1edHEnq8STBdG3G5tl7L95R4Vhx8UjkCwRz3H_1ZqeS9R/exec',
            'class' => GoogleScriptSource::class,
            'options' => ['url' => 'https://script.google.com/macros/s/AKfycbwSaMDWmzsAfIT3cD8NI3p1edHEnq8STBdG3G5tl7L95R4Vhx8UjkCwRz3H_1ZqeS9R/callback', 'sheet' => 'Свод_план'],
        ],
    ],
];
