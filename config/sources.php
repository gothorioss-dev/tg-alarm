<?php

use App\Reconcile\PriorityStrategy;
use App\Schedule\Kind;
use App\Sources\RssSource;
use App\Sources\TelegramChannelSource;

return [

    /*
     * How conflicting versions are resolved. The class implements
     * App\Reconcile\ReconcileStrategy.
     */
    'strategy' => PriorityStrategy::class,

    /*
     * Without declared update times, publications of different sources closer
     * than this are treated as the same version, so priority decides.
     */
    'same_version_minutes' => 10,

    /* A delayed copy of an older version is recognized within this window. */
    'twin_window_minutes' => 30,

    /* Emergency notices older than this are not sent. */
    'emergency_max_age_minutes' => 180,

    /* Notify about changes that concern only the time that is already over. */
    'notify_past_changes' => false,

    /* The admin is alerted after this many consecutive failed polls. */
    'alert_after_failures' => 10,

    /*
     * A source with a higher priority wins when sources disagree.
     * "page" is the public link shown to users, "kinds" lists what is taken from the source.
     */
    'sources' => [
        'site' => [
            'enabled' => true,
            'label' => 'сайт Запоріжжяобленерго',
            'page' => 'https://www.zoe.com.ua/outage/',
            'class' => RssSource::class,
            'options' => ['url' => 'https://www.zoe.com.ua/feed/?post_type=outage_schedules'],
            'priority' => 100,
            'interval' => 60,
            'kinds' => [Kind::Schedule->value, Kind::Emergency->value],
        ],
        'telegram' => [
            'enabled' => true,
            'label' => 'канал ZOE_Alarm',
            'page' => 'https://t.me/zoe_alarm',
            'class' => TelegramChannelSource::class,
            'options' => ['channel' => 'zoe_alarm'],
            'priority' => 50,
            'interval' => 60,
            'kinds' => [Kind::Schedule->value],
        ],
    ],
];
