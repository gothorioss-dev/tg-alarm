<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Schedule\Queues;

/** Keyboards of the bot screens. */
final class Keyboards
{
    public const SCHEDULE = '📅 Графік';

    public const QUEUES = '⚡ Мої черги';

    public const SETTINGS = '⚙️ Налаштування';

    public const GUIDE = '📖 Довідник';

    public const INVITE = '👥 Запросити';

    /**
     * Main menu. A message carries either this or inline buttons.
     *
     * @return array<string, mixed>
     */
    public static function bottom(): array
    {
        return [
            'keyboard' => [
                [['text' => self::SCHEDULE], ['text' => self::QUEUES]],
                [['text' => self::SETTINGS], ['text' => self::GUIDE]],
                [['text' => self::INVITE]],
            ],
            'resize_keyboard' => true,
        ];
    }

    /** Changes together with the bottom keyboard. */
    public static function version(): string
    {
        return substr(hash('sha256', json_encode(self::bottom(), JSON_THROW_ON_ERROR)), 0, 8);
    }

    /** Screen opened by a bottom button, null for any other text. */
    public static function screen(string $text): ?string
    {
        // Clients may drop the emoji variation selector.
        $plain = fn (string $value) => str_replace("\u{FE0F}", '', $value);

        return match ($plain($text)) {
            $plain(self::SCHEDULE) => 'schedule',
            $plain(self::QUEUES) => 'queue',
            $plain(self::SETTINGS) => 'settings',
            $plain(self::GUIDE) => 'help',
            $plain(self::INVITE) => 'invite',
            default => null,
        };
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

    /**
     * Opens the Telegram chat picker with a ready message.
     *
     * @return array<string, mixed>
     */
    public static function invite(string $link, string $text): array
    {
        $url = 'https://t.me/share/url?url='.rawurlencode($link).'&text='.rawurlencode($text);

        return ['inline_keyboard' => [[['text' => '📨 Надіслати запрошення', 'url' => $url]]]];
    }

    /** @return array{text: string, callback_data: string} */
    private static function inline(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }
}
