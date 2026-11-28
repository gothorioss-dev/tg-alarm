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
        if (preg_match('/\bС?ГАВ\b|графік\w* аварійних відключень/ui', $text)) {
            return Kind::Emergency;
        }
        if ($hasGpv && $this->isFullCancellation($text)) {
            return Kind::Schedule;
        }

        return $hasGpv ? Kind::Unknown : Kind::Other;
    }

    /** @throws ScheduleFormatException when any part of the list is not understood */
    public function parse(string $text, CarbonImmutable $publishedAt): ParsedSchedule
    {
        $local = $publishedAt->setTimezone(self::TZ);

        return new ParsedSchedule(
            $this->parseDate($text, $local),
            $this->isFullCancellation($text) ? new DaySchedule([]) : $this->parseQueues($text),
            $this->parseDeclaredAt($text, $local),
        );
    }

    private function parseDate(string $text, CarbonImmutable $local): string
    {
        $dates = $this->dates($text);

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

    /** @return array<string, array{int, int}> distinct [day, month] pairs of the header */
    private function dates(string $text): array
    {
        // Paragraphs after the list may mention other dates, so only the header is used.
        $header = preg_split('/^\s*\d[.,]\d\b/mu', $text)[0];
        preg_match_all('/(\d{1,2})\s+('.implode('|', array_keys(self::MONTHS)).')/ui', $header, $matches, PREG_SET_ORDER);
        $dates = [];

        foreach ($matches as $match) {
            $month = self::MONTHS[mb_strtolower($match[2])];
            $dates[(int) $match[1].'.'.$month] = [(int) $match[1], $month];
        }

        return $dates;
    }

    /**
     * All outages of one whole day are called off. Anything partial, conditional or unclear
     * is not a cancellation: a wrong guess would hide real outages.
     */
    private function isFullCancellation(string $text): bool
    {
        $text = preg_replace('/\(\s*оновлено[^)]*\)/ui', '', $text);

        return count($this->dates($text)) === 1
            && preg_match('/скасован|\bне\s+(?:будуть\s+)?(?:застосов|діятимуть)/ui', $text)
            && ! preg_match('/\bне\s+скасован|черг|частков|окрім|крім|деяк|тимчасов|продовж|залиша|перен[оі]с|можлив|якщо/ui', $text)
            && ! preg_match('/\d{1,2}\s*[:–-]\s*\d{2}|\b(?:з|із|до|після|від|о|об)\s+\d/ui', $text);
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
                // "10:30 – 15:30. 19:30 – 22:00": a full stop separates intervals too.
                preg_split('/\s*[,;]\s*|\.\s+(?=\d)/u', rtrim($match[3], ' .;,')),
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
