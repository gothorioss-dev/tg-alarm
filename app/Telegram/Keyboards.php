<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Schedule\Queues;

/** Keyboards of the bot screens. */
final class Keyboards
{
    public const MENU = '☰ Меню';

    /**
     * The only bottom button. A message carries either this or inline buttons.
     *
     * @return array<string, mixed>
     */
    public static function bottom(): array
    {
        return ['keyboard' => [[['text' => self::MENU]]], 'resize_keyboard' => true];
    }

    /** @return array<string, mixed> */
    public static function menu(): array
    {
        return ['inline_keyboard' => [
            [self::inline('⚡ Змінити / додати чергу', 'queue')],
            [self::inline('⚙️ Налаштування', 'settings')],
            [self::inline('📖 Довідник', 'help'), self::inline('🔗 Джерела', 'sources')],
        ]];
    }

    /** @return array<string, mixed> */
    public static function settings(Chat $chat): array
    {
        $mark = fn (bool $selected) => $selected ? '✅ ' : '';
        $minutes = array_map(
            fn (int $m) => self::inline($mark($chat->remind_minutes === $m)."{$m} хв", "r:{$m}"),
            config('telegram.remind_minutes'),
        );

        return ['inline_keyboard' => [
            [$chat->active
                ? self::inline('🔕 Вимкнути сповіщення', 'stop')
                : self::inline('🔔 Увімкнути сповіщення', 'enable')],
            $minutes,
            [self::inline($mark($chat->remind_minutes === null).'Не нагадувати', 'r:0')],
        ]];
    }

    /** @return array<string, mixed> */
    public static function queues(Chat $chat): array
    {
        $selected = $chat->subscriptions()->pluck('queue')->all();
        $buttons = array_map(
            fn (string $queue) => self::inline((in_array($queue, $selected, true) ? '✅ ' : '').$queue, "q:{$queue}"),
            Queues::ALL,
        );

        return ['inline_keyboard' => [...array_chunk($buttons, 4), [self::inline('💾 Готово', 'done')]]];
    }

    /** @return array{text: string, callback_data: string} */
    private static function inline(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }
}
