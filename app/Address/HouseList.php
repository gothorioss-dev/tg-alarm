<?php

namespace App\Address;

/** Houses as listed by the source: "22-28|40а|6 (5 пов)|134-182 парні". */
final class HouseList
{
    /** @var list<string> parts that are not understood */
    private array $unknown = [];

    /** @var list<array{type: string, from: int, to: int, parity: ?int, key: string, note: string}> */
    private array $items = [];

    public function __construct(string $text)
    {
        $number = null;
        $parts = array_filter(array_map(trim(...), preg_split('/[|;,](?![^(]*\))/u', $text) ?: []), fn (string $part) => $part !== '');

        if ($parts === []) {
            $this->add('all', note: 'номери будинків не вказано');
        }

        foreach ($parts as $part) {
            $number = $this->parse($part, $number);
        }
    }

    /** @return string|null a note to this house, possibly empty; null when the house is not listed */
    public function match(string $house): ?string
    {
        $key = AddressText::house($house);
        $number = preg_match('/^\d+/', $key, $match) ? (int) $match[0] : null;
        $notes = [];

        foreach ($this->items as $item) {
            $found = match ($item['type']) {
                'all' => true,
                'house' => $item['key'] === $key,
                default => $number !== null && $number >= $item['from'] && $number <= $item['to']
                    && ($item['parity'] === null || $number % 2 === $item['parity']),
            };

            if ($found) {
                $notes[] = $item['note'];
            }
        }

        if ($notes === []) {
            return null;
        }

        // The whole house is listed at least once.
        return in_array('', $notes, true) ? '' : implode('; ', array_unique($notes));
    }

    /** @return list<string> */
    public function unknown(): array
    {
        return $this->unknown;
    }

    /** @return int|null the number that a single letter after it belongs to */
    private function parse(string $part, ?int $number): ?int
    {
        preg_match_all('/\(([^)]*)\)/u', $part, $notes);
        $note = trim(implode(', ', array_filter(array_map(trim(...), $notes[1]))));
        $text = AddressText::house(trim((string) preg_replace('/\([^)]*\)?/u', ' ', $part)));

        if (preg_match('/^(\d+)(\p{L}?)(?:\/\d+\p{L}?)?$/u', $text, $match)) {
            $this->add('house', key: $text, note: $note);

            return (int) $match[1];
        }
        if ($number !== null && preg_match('/^\p{L}$/u', $text)) {
            $this->add('house', key: $number.$text, note: $note);

            return $number;
        }
        if (preg_match('/^(\d+)\p{L}?(?:\/\d+)?-(\d+)\p{L}?(?:\/\d+)?(?:\s*(парні|непарні))?$/u', $text, $match) && (int) $match[1] <= (int) $match[2]) {
            [$from, $to] = [(int) $match[1], (int) $match[2]];
            $parity = match ($match[3] ?? '') {
                'парні' => 0,
                'непарні' => 1,
                // One side of the street.
                default => $from % 2 === $to % 2 ? $from % 2 : null,
            };
            $this->add('range', $from, $to, $parity, note: $note);

            return null;
        }
        if (preg_match('/^(парні|непарні)$/u', $text)) {
            $this->add('range', 0, PHP_INT_MAX, $text === 'парні' ? 0 : 1, note: $note);

            return null;
        }
        if (preg_match('/^(повністю|вся вулиця|вся|усі|всі)$/u', $text)) {
            $this->add('all', note: $note);

            return null;
        }
        // "21 п.1-4", "43/Тургенєва24": a part of the house or its second name.
        if (preg_match('/^(\d+\p{L}?)(?:\s+|\/)(\p{L}.*)$/u', $text, $match)) {
            $this->add('house', key: $match[1], note: trim($match[2].($note === '' ? '' : ", {$note}")));

            return null;
        }

        if (preg_match('/^(\d+)((?:кв|пов|прим|під|оп)\.?\s*\d.*)$/u', $text, $match)) {
            $this->add('house', key: $match[1], note: trim($match[2].($note === '' ? '' : ", {$note}")));

            return null;
        }

        // A name instead of a number can still be chosen as it is.
        $this->add('house', key: $text, note: $note);
        $this->unknown[] = $part;

        return null;
    }

    private function add(string $type, int $from = 0, int $to = 0, ?int $parity = null, string $key = '', string $note = ''): void
    {
        $this->items[] = ['type' => $type, 'from' => $from, 'to' => $to, 'parity' => $parity, 'key' => $key, 'note' => $note];
    }
}
