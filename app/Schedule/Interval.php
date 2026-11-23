<?php

namespace App\Schedule;

/** Half-open outage interval in minutes from the start of the day. */
final readonly class Interval
{
    public function __construct(public int $from, public int $to)
    {
        if ($from < 0 || $to > 1440 || $from >= $to) {
            throw new ScheduleFormatException("Invalid interval {$from}-{$to}");
        }
    }

    public function __toString(): string
    {
        return self::time($this->from).'–'.self::time($this->to);
    }

    private static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
