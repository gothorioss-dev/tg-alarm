<?php

namespace App\Address;

/** Why there is no electricity. */
enum Kind: string
{
    case Emergency = 'emergency';
    case Urgent = 'urgent';
    case Planned = 'planned';
    case Other = 'other';

    public static function detect(string $text): self
    {
        $text = mb_strtolower($text);

        return match (true) {
            str_contains($text, 'авар'), str_contains($text, 'пошкодж') => self::Emergency,
            str_contains($text, 'термін') => self::Urgent,
            str_contains($text, 'планов') => self::Planned,
            default => self::Other,
        };
    }
}
