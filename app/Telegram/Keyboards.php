<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Schedule\Queues;

/** Inline keyboards; they work the same in private chats and groups. */
final class Keyboards
{
    /** Opens the screen in a new message, so the notification above stays intact. */
    public const NEW_PREFIX = 'new:';

    /** @return array<string, mixed> */
    public static function menu(Chat $chat): array
    {
        return ['inline_keyboard' => [
            [self::inline('📅 Графік', 'schedule')],
            [self::inline('⚙️ Змінити / додати чергу', 'queue')],
            [$chat->active
                ? self::inline('🔕 Вимкнути сповіщення', 'stop')
                : self::inline('🔔 Увімкнути сповіщення', 'enable')],
            [self::inline('ℹ️ Допомога', 'help')],
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

    /** @return array<string, mixed> */
    public static function schedule(): array
    {
        return ['inline_keyboard' => [[self::inline('🔄 Оновити', 'schedule'), self::inline('☰ Меню', 'menu')]]];
    }

    /** @return array<string, mixed> */
    public static function back(): array
    {
        return ['inline_keyboard' => [[self::inline('☰ Меню', 'menu')]]];
    }

    /** @return array<string, mixed> */
    public static function notification(): array
    {
        return ['inline_keyboard' => [[
            self::inline('📅 Графік', self::NEW_PREFIX.'schedule'),
            self::inline('☰ Меню', self::NEW_PREFIX.'menu'),
        ]]];
    }

    /** @return array{text: string, callback_data: string} */
    private static function inline(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }
}
