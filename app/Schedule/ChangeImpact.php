<?php

namespace App\Schedule;

/** What a schedule change of one queue changes, and whether it is still relevant. */
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

    /** Every changed part is already over, so nothing can be done about it. */
    public function isPast(): bool
    {
        foreach ([...$this->added, ...$this->removed] as $interval) {
            if ($this->minute === null || $interval->to > $this->minute) {
                return false;
            }
        }

        return true;
    }
}
