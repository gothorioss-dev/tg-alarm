<?php

namespace App\Schedule;

use App\Models\Schedule;
use Carbon\CarbonImmutable;

/** Stored schedules of yesterday, today and tomorrow as continuous outages. */
final class OutageTimeline
{
    /** @param array<string, DaySchedule> $days by date, ascending */
    private function __construct(private CarbonImmutable $now, private array $days) {}

    public static function load(): self
    {
        $now = CarbonImmutable::now(config('telegram.timezone'));
        $days = Schedule::query()
            ->whereBetween('date', [$now->subDay()->format('Y-m-d'), $now->addDay()->format('Y-m-d')])
            ->orderBy('date')->get()
            ->mapWithKeys(fn (Schedule $s) => [$s->date => $s->day()])->all();

        return new self($now, $days);
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    /** @return array<string, DaySchedule> today and later */
    public function days(): array
    {
        return array_filter($this->days, fn (string $date) => $date >= $this->now->format('Y-m-d'), ARRAY_FILTER_USE_KEY);
    }

    public function knowsToday(): bool
    {
        return isset($this->days[$this->now->format('Y-m-d')]);
    }

    /** @return list<Outage> */
    public function outages(string $queue): array
    {
        $result = [];

        foreach ($this->days as $date => $day) {
            $midnight = CarbonImmutable::parse($date, $this->now->timezone);

            foreach ($day->intervals($queue) as $interval) {
                // setTime keeps wall-clock time on daylight saving days; 24:00 becomes the next midnight.
                $start = $midnight->setTime(intdiv($interval->from, 60), $interval->from % 60);
                $end = $midnight->setTime(intdiv($interval->to, 60), $interval->to % 60);
                $last = end($result);

                if ($last && $last->end->equalTo($start)) {
                    array_pop($result);
                    $start = $last->start;
                }
                $result[] = new Outage($start, $end);
            }
        }

        return $result;
    }

    public function current(string $queue): ?Outage
    {
        foreach ($this->outages($queue) as $outage) {
            if ($outage->start <= $this->now && $outage->end > $this->now) {
                return $outage;
            }
        }

        return null;
    }

    public function next(string $queue): ?Outage
    {
        foreach ($this->outages($queue) as $outage) {
            if ($outage->start > $this->now) {
                return $outage;
            }
        }

        return null;
    }
}
