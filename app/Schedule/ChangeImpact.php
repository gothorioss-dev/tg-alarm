<?php

namespace App\Schedule;

/** What a schedule change of one queue means at the given minute of that day. */
final readonly class ChangeImpact
{
    /** @var list<Interval> */
    public array $added;

    /** @var list<Interval> */
    public array $removed;

    /** Outage in effect by the old schedule. */
    public ?Interval $was;

    /** Outage in effect by the new schedule. */
    public ?Interval $is;

    /**
     * @param  list<Interval>  $old
     * @param  list<Interval>  $new
     * @param  int|null  $minute  null when the schedule is not for today
     */
    public function __construct(array $old, array $new, public ?int $minute)
    {
        $this->added = DaySchedule::subtract($new, $old);
        $this->removed = DaySchedule::subtract($old, $new);
        $this->was = $this->at($old);
        $this->is = $this->at($new);
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

    /** The change affects the outage that is, or was to be, in effect right now. */
    public function affectsNow(): bool
    {
        return $this->was?->to !== $this->is?->to;
    }

    /** @param list<Interval> $intervals */
    private function at(array $intervals): ?Interval
    {
        foreach ($intervals as $interval) {
            if ($this->minute !== null && $interval->from <= $this->minute && $interval->to > $this->minute) {
                return $interval;
            }
        }

        return null;
    }
}
