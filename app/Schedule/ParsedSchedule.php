<?php

namespace App\Schedule;

use Carbon\CarbonImmutable;

final readonly class ParsedSchedule
{
    public function __construct(
        public string $date,
        public DaySchedule $schedule,
        /** Update time declared in the title, null when the title has none. */
        public ?CarbonImmutable $declaredAt,
    ) {}
}
