<?php

namespace App\Schedule;

/** What a schedule change of one queue changes, relative to the current minute of the day. */
final readonly class ChangeImpact
{
    /** @var list<Interval> */
    public array $added;

    /** @var list<Interval> */
    public array $removed;

    /**
     * @param  list<Interval>  $old
     * @param  list<Interval>  $new
     * @param  int|null  $minute  null when the schedule is not for today
     */
    public function __construct(array $old, array $new, public ?int $minute)
    {
        $this->added = DaySchedule::subtract($new, $old);
        $this->removed = DaySchedule::subtract($old, $new);
    }

    /** A changed part had already started by the given minute of the day. */
    public function startedBy(?int $minute): bool
    {
        foreach ([...$this->added, ...$this->removed] as $interval) {
            if ($minute !== null && $interval->from <= $minute) {
                return true;
            }
        }

        return false;
    }
}
