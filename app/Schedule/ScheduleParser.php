<?php

namespace App\Schedule;

use Carbon\CarbonImmutable;

final class ScheduleParser
{
    private const TZ = 'Europe/Kyiv';

    private const MONTHS = [
        'січня' => 1, 'лютого' => 2, 'березня' => 3, 'квітня' => 4, 'травня' => 5, 'червня' => 6,
        'липня' => 7, 'серпня' => 8, 'вересня' => 9, 'жовтня' => 10, 'листопада' => 11, 'грудня' => 12,
    ];

    public function classify(string $text): Kind
    {
        $hasGpv = (bool) preg_match('/\bГПВ\b/ui', $text);

        if ($hasGpv && preg_match('/^\s*\d[.,]\d\s*:?\s*\d{1,2}:\d{2}/mu', $text)) {
            return Kind::Schedule;
        }
        if (preg_match('/\bСГАВ\b|спеціальн\w+ графік\w+ аварійних відключень/ui', $text)) {
            return Kind::Emergency;
        }

        return $hasGpv ? Kind::Unknown : Kind::Other;
    }

    /** @throws ScheduleFormatException when any part of the list is not understood */
    public function parse(string $text, CarbonImmutable $publishedAt): ParsedSchedule
    {
        $local = $publishedAt->setTimezone(self::TZ);

        return new ParsedSchedule(
            $this->parseDate($text, $local),
            $this->parseQueues($text),
            $this->parseDeclaredAt($text, $local),
        );
    }

    private function parseDate(string $text, CarbonImmutable $local): string
    {
        // Paragraphs after the list may mention other dates, so only the header is used.
        $header = preg_split('/^\s*\d[.,]\d\b/mu', $text)[0];
        preg_match_all('/(\d{1,2})\s+('.implode('|', array_keys(self::MONTHS)).')/ui', $header, $matches, PREG_SET_ORDER);
        $dates = [];

        foreach ($matches as $match) {
            $dates[(int) $match[1].'.'.self::MONTHS[mb_strtolower($match[2])]] = [(int) $match[1], self::MONTHS[mb_strtolower($match[2])]];
        }
        if (count($dates) !== 1) {
            throw new ScheduleFormatException('Expected exactly one schedule date, found '.count($dates));
        }

        [$day, $month] = reset($dates);

        // The text has no year: take the one closest to the publication time.
        foreach ([$local->year, $local->year + 1, $local->year - 1] as $year) {
            if (! checkdate($month, $day, $year)) {
                continue;
            }
            $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::TZ);

            if (abs($date->diffInDays($local->startOfDay())) <= 7) {
                return $date->format('Y-m-d');
            }
        }

        throw new ScheduleFormatException("Schedule date {$day}.{$month} is too far from the publication date");
    }

    private function parseQueues(string $text): DaySchedule
    {
        $queues = [];

        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $line));
            $isQueue = (bool) preg_match('/^(\d+)[.,](\d+)\s*[:–-]?\s*(.*)$/u', $line, $match);

            if (! $isQueue) {
                // A short line with a time range is a mangled row: refuse it, so it is not read as "no outages".
                if (mb_strlen($line) < 80 && preg_match('/\d{1,2}[:.]\d{2}\s*[-–—]/u', $line)) {
                    throw new ScheduleFormatException("Unrecognized line: {$line}");
                }

                continue;
            }

            $queue = $match[1].'.'.$match[2];

            if (! in_array($queue, Queues::ALL, true)) {
                throw new ScheduleFormatException("Unknown queue {$queue}");
            }
            if (isset($queues[$queue])) {
                throw new ScheduleFormatException("Duplicate queue {$queue}");
            }

            $queues[$queue] = array_map(
                $this->parseInterval(...),
                preg_split('/\s*[,;]\s*/u', rtrim($match[3], ' .;,')),
            );
        }

        if ($queues === []) {
            throw new ScheduleFormatException('No queues found');
        }

        return new DaySchedule($queues);
    }

    private function parseInterval(string $value): Interval
    {
        if (! preg_match('/^(\d{1,2}):([0-5]\d)\s*[-–—]\s*(\d{1,2}):([0-5]\d)$/u', trim($value), $m)) {
            throw new ScheduleFormatException("Unrecognized interval: {$value}");
        }

        return new Interval((int) $m[1] * 60 + (int) $m[2], (int) $m[3] * 60 + (int) $m[4]);
    }

    private function parseDeclaredAt(string $text, CarbonImmutable $local): ?CarbonImmutable
    {
        if (! preg_match('/оновлено\s+(?:о\s+)?([01]?\d|2[0-3]):([0-5]\d)/ui', $text, $m)) {
            return null;
        }

        $declared = $local->setTime((int) $m[1], (int) $m[2]);

        // Declared before midnight, published after it.
        if ($declared->greaterThan($local->addHour())) {
            $declared = $declared->subDay();
        }

        return $declared->utc();
    }
}
