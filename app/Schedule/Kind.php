<?php

namespace App\Schedule;

enum Kind: string
{
    case Schedule = 'schedule';
    case Emergency = 'emergency';
    /** Mentions planned outages but has no recognizable list. */
    case Unknown = 'unknown';
    case Other = 'other';
}
