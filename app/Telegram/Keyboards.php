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
    public static function menu(): array
    {
        return ['inline_keyboard' => [
            [self::inline('📅 Графік', 'schedule')],
            [self::inline('⚡ Змінити / додати чергу', 'queue')],
            [self::inline('⚙️ Налаштування', 'settings')],
            [self::inline('📘 Інструкція', 'help'), self::inline('📖 Довідник кнопок', 'buttons')],
        ]];
    }

    /** @return array<string, mixed> */
    public static function settings(Chat $chat, string $reminderLabel): array
    {
        return ['inline_keyboard' => [
            [$chat->active
                ? self::inline('🔕 Вимкнути сповіщення', 'stop')
                : self::inline('🔔 Увімкнути сповіщення', 'enable')],
            [self::inline("⏰ Нагадування: {$reminderLabel}", 'remind')],
            [self::inline('☰ Меню', 'menu')],
        ]];
    }

    /** @return array<string, mixed> */
    public static function reminders(Chat $chat): array
    {
        $mark = fn (bool $selected) => $selected ? '✅ ' : '';
        $buttons = array_map(
            fn (int $m) => self::inline($mark($chat->remind_minutes === $m)."за {$m} хв", "r:{$m}"),
            config('telegram.remind_minutes'),
        );

        return ['inline_keyboard' => [
            ...array_chunk($buttons, 2),
            [self::inline($mark($chat->remind_minutes === null).'Не нагадувати', 'r:0')],
            [self::inline('⬅️ Налаштування', 'settings')],
        ]];
    }

    /** @return array<string, mixed> */
    public static function manual(bool $hasQueues): array
    {
        return $hasQueues ? self::back() : ['inline_keyboard' => [
            [self::inline('⚡ Обрати чергу', 'queue')],
            [self::inline('📖 Довідник кнопок', 'buttons')],
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
