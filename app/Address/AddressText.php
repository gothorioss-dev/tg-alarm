<?php

namespace App\Address;

/** Brings spelling of addresses to a common form. */
final class AddressText
{
    /** Latin letters used instead of Cyrillic ones. */
    private const LATIN = ['A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'I' => 'І', 'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т', 'X' => 'Х', 'Y' => 'У',
        'a' => 'а', 'b' => 'б', 'c' => 'с', 'e' => 'е', 'i' => 'і', 'k' => 'к', 'm' => 'м', 'o' => 'о', 'p' => 'р', 't' => 'т', 'x' => 'х', 'y' => 'у'];

    /** Different spelling, the same name. */
    private const SIMILAR = ['і' => 'и', 'ї' => 'и', 'ы' => 'и', 'й' => 'и', 'є' => 'е', 'э' => 'е', 'ё' => 'е', 'ґ' => 'г', 'ь' => '', 'ъ' => '', "'" => ''];

    public static function normalize(string $text): string
    {
        $text = strtr($text, self::LATIN + ['’' => "'", 'ʼ' => "'", '`' => "'", '‘' => "'", '–' => '-', '—' => '-']);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /** For a search only: forgives Ukrainian and Russian spelling. */
    public static function fold(string $text): string
    {
        $text = strtr(self::normalize($text), self::SIMILAR);

        return trim((string) preg_replace('/[^\p{L}\d]+/u', ' ', $text));
    }

    /** "11 а" and "11-А" are the same house as "11а". */
    public static function house(string $text): string
    {
        $text = (string) preg_replace('/^(?:будинок|буд\.?|дом|д\.|№)\s*(?=\d)/u', '', self::normalize($text));
        $text = (string) preg_replace('/[,\s]+(?:квартира|кв)\.?\s*\d+\S*$/u', '', $text);
        $text = (string) preg_replace('/(?<=\d)\s*["\']\s*(\p{L})\s*["\']/u', '$1', $text);
        $text = (string) preg_replace('/(?<=\d)\s*-?\s*(\p{L})(?=$|\/|\s*-\s*\d)/u', '$1', $text);

        return (string) preg_replace('/\s*([\/-])\s*/u', '$1', $text);
    }

    /** @return array{string, ?string} street and house */
    public static function split(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text), ' ,.;');
        $text = (string) preg_replace('/[,\s]+(?:квартира|кв)\.?\s*\d+\S*$/ui', '', $text);

        if (preg_match('/^(.*?\p{L}.*?)[\s,]+(?:(?:будинок|буд\.?|дом|д\.)\s*)?№?\s*(\d+(?:\s*-?\s*\p{L}(?!\p{L}))?(?:\s*\/\s*\d+\p{L}?)?)$/ui', $text, $match)) {
            return [trim($match[1], ' ,.'), $match[2]];
        }

        return [$text, null];
    }
}
