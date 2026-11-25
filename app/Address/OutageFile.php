<?php

namespace App\Address;

use App\Sources\SourceException;
use Carbon\CarbonImmutable;
use ZipArchive;

/** Reads a table of outages (.xlsx). */
final class OutageFile
{
    /** Uncompressed size of one part of the file. */
    private const MAX_PART = 10_000_000;

    private const MAX_ROWS = 5000;

    /**
     * @return non-empty-list<FileRow>
     *
     * @throws SourceException
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new SourceException('The file is not a table');
        }

        try {
            return $this->rows($this->cells($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  list<array<string, string>>  $table
     * @return non-empty-list<FileRow>
     */
    private function rows(array $table): array
    {
        [$header, $columns] = $this->columns($table);
        $rows = [];
        $last = ['street' => '', 'reason' => '', 'start_date' => '', 'start_time' => '', 'end_date' => '', 'end_time' => ''];

        foreach (array_slice($table, $header + 1) as $cells) {
            $get = fn (string $name) => $cells[$columns[$name]] ?? '';
            $houses = $get('houses');

            // The second line of the header and notes below the table.
            if ($houses === '' && $get('street') === '') {
                continue;
            }
            // The time is written once for all streets below.
            if ($get('start_date') !== '') {
                $last = [...$last, 'start_date' => '', 'start_time' => '', 'end_date' => '', 'end_time' => ''];
            }
            foreach (array_keys($last) as $name) {
                $last[$name] = $get($name) !== '' ? $get($name) : $last[$name];
            }

            $start = $this->time($last['start_date'], $last['start_time']);

            if ($last['street'] === '' || $start === null) {
                throw new SourceException('A row without a street or a start: '.implode(' | ', $cells));
            }

            $end = $this->time($last['end_date'], $last['end_time']);
            $rows[] = new FileRow($last['street'], $houses, Kind::detect($last['reason']), $start, $end !== null && $end > $start ? $end : null);
        }

        return $rows ?: throw new SourceException('The table is empty');
    }

    /**
     * @param  list<array<string, string>>  $table
     * @return array{int, array<string, string>} number of the header row and letters of columns
     */
    private function columns(array $table): array
    {
        foreach ($table as $number => $cells) {
            $columns = [];

            foreach ($cells as $column => $text) {
                $name = match (true) {
                    preg_match('/^вулиц/ui', $text) === 1 => 'street',
                    preg_match('/^буд/ui', $text) === 1 => 'houses',
                    preg_match('/^причин/ui', $text) === 1 => 'reason',
                    preg_match('/^початок/ui', $text) === 1 => 'start_date',
                    preg_match('/відновлення/ui', $text) === 1 => 'end_date',
                    default => null,
                };

                if ($name !== null) {
                    $columns[$name] = $column;
                }
            }

            if (isset($columns['street'], $columns['houses'], $columns['reason'], $columns['start_date'], $columns['end_date'])) {
                // The date and the time are under one title.
                return [$number, [...$columns, 'start_time' => $this->next($columns['start_date']), 'end_time' => $this->next($columns['end_date'])]];
            }
        }

        throw new SourceException('The header of the table is not recognized');
    }

    private function next(string $column): string
    {
        return str_increment($column);
    }

    private function time(string $date, string $time): ?CarbonImmutable
    {
        $day = match (true) {
            preg_match('/^(\d{1,2})[.\/,](\d{1,2})[.\/,](\d{4}|\d{2})\.?(?:\s*р\.?)?$/u', $date, $d) === 1 && checkdate((int) $d[2], (int) $d[1], (int) $d[3] % 100 + 2000) => CarbonImmutable::create((int) $d[3] % 100 + 2000, (int) $d[2], (int) $d[1], 0, 0, 0, config('telegram.timezone')),
            // A date stored as a number of days.
            preg_match('/^(4|5)\d{4}(\.0+)?$/', $date) === 1 => CarbonImmutable::create(1899, 12, 30, 0, 0, 0, config('telegram.timezone'))->addDays((int) $date),
            default => null,
        };

        if ($day === null) {
            return null;
        }
        if (preg_match('/^(\d{1,2})\s*[-:.–]\s*(\d{2})(?::\d{2})?$/u', $time, $t) && (int) $t[1] <= 24 && (int) $t[2] <= 59) {
            return $day->setTime((int) $t[1], (int) $t[2])->utc();
        }
        // A time stored as a part of the day.
        if (preg_match('/^(0|1)(\.\d+(E-\d+)?)?$/i', $time) && (float) $time <= 1) {
            $minutes = (int) round((float) $time * 1440);

            return $day->setTime(intdiv($minutes, 60), $minutes % 60)->utc();
        }

        return null;
    }

    /** @return list<array<string, string>> rows of cells by the letter of the column */
    private function cells(ZipArchive $zip): array
    {
        $strings = [];

        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $item) {
                $strings[] = $this->text($item);
            }
        }

        $table = [];

        foreach ($this->xml($zip, 'xl/worksheets/sheet1.xml')->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $value = match ((string) $cell['t']) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => $this->text($cell->is),
                    default => (string) $cell->v,
                };
                $value = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));

                if ($value !== '') {
                    $cells[(string) preg_replace('/\d+$/', '', (string) $cell['r'])] = $value;
                }
            }

            if ($cells !== [] && count($table) < self::MAX_ROWS) {
                $table[] = $cells;
            }
        }

        return $table;
    }

    /** Text may be split by formatting. */
    private function text(\SimpleXMLElement $item): string
    {
        $text = (string) $item->t;

        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private function xml(ZipArchive $zip, string $name): \SimpleXMLElement
    {
        $stat = $zip->statName($name);

        if ($stat === false || $stat['size'] > self::MAX_PART) {
            throw new SourceException("Part {$name} is missing or too big");
        }

        $content = (string) $zip->getFromName($name);

        if (stripos($content, '<!DOCTYPE') !== false || ($xml = @simplexml_load_string($content, options: LIBXML_NONET)) === false) {
            throw new SourceException("Part {$name} is not recognized");
        }

        return $xml;
    }
}
