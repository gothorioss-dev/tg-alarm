<?php

namespace App\Address;

use App\Models\City;
use App\Models\Street;

/** Finds streets by a current or former name. */
final class StreetSearch
{
    private const TYPES = [
        'вул' => 'вулиця|вул|улица|ул',
        'пров' => 'провулок|пров|переулок|пер',
        'пр' => 'проспект|просп|пр т|пр',
        'бул' => 'бульвар|бульв|бул|б р',
        'пл' => 'площа|площадь|пл',
        'шосе' => 'шосе|шоссе',
        'проїзд' => 'проїзд|проезд',
    ];

    /** @return array{list<Street>, bool} the best matches and whether the names are the same */
    public function find(City $city, string $query): array
    {
        [$wanted, $type] = $this->name($query);

        if (mb_strlen($wanted) < 2) {
            return [[], false];
        }

        $groups = [[], [], [], []];

        foreach (Street::query()->where('city_id', $city->id)->where('active', true)->orderBy('name')->get(['id', 'city_id', 'name', 'key']) as $street) {
            $score = $this->score($street->name, $wanted);

            if ($score !== null) {
                $groups[$score][] = $street;
            }
        }

        $exact = $groups[0] !== [];
        $found = $exact ? $groups[0] : ([...$groups[1], ...$groups[2]] ?: $groups[3]);
        $sameType = $type === null ? [] : array_values(array_filter($found, fn (Street $street) => $this->name($street->name)[1] === $type));

        return [$sameType ?: $found, $exact];
    }

    /** The only listed street of the same type with the same name, former name or order of words. */
    public function same(City $city, string $name): ?Street
    {
        [, $type] = $this->name($name);
        $wanted = array_map($this->sorted(...), $this->aliases($name));
        $whole = $this->sorted($this->name($name)[0]);
        $found = ['whole' => [], 'alias' => []];

        foreach (Street::query()->where('city_id', $city->id)->where('active', true)->where('listed', true)->get(['id', 'city_id', 'name', 'key', 'listed']) as $street) {
            if ($this->name($street->name)[1] !== $type) {
                continue;
            }

            $aliases = array_map($this->sorted(...), $this->aliases($street->name));

            if (in_array($whole, $aliases, true)) {
                $found['whole'][] = $street;
            } elseif (array_intersect($wanted, $aliases) !== []) {
                $found['alias'][] = $street;
            }
        }

        foreach ($found as $streets) {
            if ($streets !== []) {
                return count($streets) === 1 ? $streets[0] : null;
            }
        }

        return null;
    }

    private function sorted(string $text): string
    {
        $words = explode(' ', $text);
        sort($words);

        return implode(' ', $words);
    }

    private function score(string $name, string $wanted): ?int
    {
        $best = null;

        foreach ($this->aliases($name) as $alias) {
            $score = match (true) {
                $alias === $wanted => 0,
                str_starts_with($alias, $wanted) => 1,
                str_contains($alias, $wanted) => 2,
                mb_strlen($wanted) >= 5 && $this->distance($alias, $wanted) <= 2 => 3,
                default => null,
            };

            if ($score !== null && ($best === null || $score < $best)) {
                $best = $score;
            }
        }

        return $best;
    }

    /** @return list<string> the whole name, the current and the former ones */
    private function aliases(string $name): array
    {
        $parts = [$name, ...(preg_split('/[()]/u', $name) ?: [])];

        return array_values(array_unique(array_filter(array_map(fn (string $part) => $this->name($part)[0], $parts))));
    }

    /** @return array{string, ?string} name without the type of the street, and the type */
    private function name(string $text): array
    {
        $text = AddressText::fold($text);

        foreach (self::TYPES as $type => $words) {
            $words = implode('|', array_map(AddressText::fold(...), explode('|', $words)));

            if (preg_match("/^(?:{$words}) (.+)$/u", $text, $match) || preg_match("/^(.+) (?:{$words})$/u", $text, $match)) {
                return [trim($match[1]), $type];
            }
        }

        return [$text, null];
    }

    private function distance(string $a, string $b): int
    {
        // One byte per letter.
        return levenshtein(mb_convert_encoding($a, 'Windows-1251', 'UTF-8'), mb_convert_encoding($b, 'Windows-1251', 'UTF-8'));
    }
}
