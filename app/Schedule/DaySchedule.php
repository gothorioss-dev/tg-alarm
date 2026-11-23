<?php

namespace App\Schedule;

/** Outages of all queues for one date. A queue without outages has an empty list. */
final readonly class DaySchedule
{
    /** @var array<string, list<Interval>> */
    private array $queues;

    /** @param array<string, list<Interval>> $queues */
    public function __construct(array $queues)
    {
        $normalized = [];

        foreach (Queues::ALL as $queue) {
            $normalized[$queue] = self::normalize($queues[$queue] ?? []);
        }

        $this->queues = $normalized;
    }

    /** @param array<string, list<array{int, int}>> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            fn (array $list) => array_map(fn (array $pair) => new Interval($pair[0], $pair[1]), $list),
            $data,
        ));
    }

    /** @return array<string, list<array{int, int}>> */
    public function toArray(): array
    {
        return array_map(
            fn (array $list) => array_map(fn (Interval $i) => [$i->from, $i->to], $list),
            $this->queues,
        );
    }

    /** @return list<Interval> */
    public function intervals(string $queue): array
    {
        return $this->queues[$queue] ?? [];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function changedQueues(self $previous): array
    {
        return array_values(array_filter(
            Queues::ALL,
            fn (string $queue) => $this->intervals($queue) != $previous->intervals($queue),
        ));
    }

    /**
     * Parts of the first list not covered by the second one.
     *
     * @param  list<Interval>  $list
     * @param  list<Interval>  $minus
     * @return list<Interval>
     */
    public static function subtract(array $list, array $minus): array
    {
        $result = [];

        foreach ($list as $interval) {
            $start = $interval->from;

            foreach ($minus as $cut) {
                if ($cut->to <= $start || $cut->from >= $interval->to) {
                    continue;
                }
                if ($cut->from > $start) {
                    $result[] = new Interval($start, $cut->from);
                }
                $start = $cut->to;
            }

            if ($start < $interval->to) {
                $result[] = new Interval($start, $interval->to);
            }
        }

        return $result;
    }

    /**
     * Sorts intervals and joins adjacent ones so that equal schedules compare equal.
     *
     * @param  list<Interval>  $list
     * @return list<Interval>
     */
    private static function normalize(array $list): array
    {
        usort($list, fn (Interval $a, Interval $b) => $a->from <=> $b->from);
        $result = [];

        foreach ($list as $interval) {
            $last = end($result);

            if ($last && $interval->from < $last->to) {
                throw new ScheduleFormatException("Overlapping intervals {$last} and {$interval}");
            }
            if ($last && $interval->from === $last->to) {
                array_pop($result);
                $interval = new Interval($last->from, $interval->to);
            }
            $result[] = $interval;
        }

        return $result;
    }
}
