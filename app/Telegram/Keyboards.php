<?php

namespace App\Telegram;

use App\Address\CityRegistry;
use App\Models\Address;
use App\Models\Chat;
use App\Models\Street;
use App\Schedule\Queues;

/** Keyboards of the bot screens. */
final class Keyboards
{
    public const SCHEDULE = '📅 Графік';

    public const QUEUES = '⚡ Мої черги';

    public const SETTINGS = '🔔 Сповіщення';

    /** Label of the settings button in keyboards sent earlier. */
    private const OLD_SETTINGS = '⚙️ Налаштування';

    public const GUIDE = '📖 Довідник';

    public const INVITE = '👥 Запросити';

    /** Shown to the admin only. */
    public const STATISTICS = '📊 Статистика';

    public const ADDRESS = '🚨 Відключення за адресою';

    /**
     * Main menu. A message carries either this or inline buttons.
     *
     * @return array<string, mixed>
     */
    public static function bottom(int $telegramId): array
    {
        return [
            'keyboard' => [
                [['text' => self::SCHEDULE], ['text' => self::QUEUES]],
                [['text' => self::ADDRESS]],
                [['text' => self::SETTINGS], ['text' => self::GUIDE]],
                [['text' => self::INVITE], ...(self::isAdmin($telegramId) ? [['text' => self::STATISTICS]] : [])],
            ],
            'resize_keyboard' => true,
        ];
    }

    public static function isAdmin(int $telegramId): bool
    {
        return $telegramId !== 0 && $telegramId === (int) config('telegram.admin_id');
    }

    /** Changes together with the bottom keyboard. */
    public static function version(int $telegramId): string
    {
        return substr(hash('sha256', json_encode(self::bottom($telegramId), JSON_THROW_ON_ERROR)), 0, 8);
    }

    /** Screen opened by a bottom button, null for any other text. */
    public static function screen(string $text, int $telegramId): ?string
    {
        // Clients may drop the emoji variation selector.
        $plain = fn (string $value) => str_replace("\u{FE0F}", '', $value);

        return match ($plain($text)) {
            $plain(self::SCHEDULE) => 'schedule',
            $plain(self::QUEUES) => 'queue',
            $plain(self::SETTINGS), $plain(self::OLD_SETTINGS) => 'settings',
            $plain(self::GUIDE) => 'help',
            $plain(self::INVITE) => 'invite',
            $plain(self::ADDRESS) => 'address',
            $plain(self::STATISTICS) => self::isAdmin($telegramId) ? 'stats' : null,
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
            [$chat->silent
                ? self::inline('🔊 Увімкнути звук', 'sound')
                : self::inline('🔇 Вимкнути звук', 'mute')],
            $minutes,
            [self::inline($mark($chat->remind_minutes === null).'Не нагадувати', 'r:0')],
        ]];
    }

    /**
     * A button carries the wanted state, so a repeated press changes nothing.
     *
     * @return array<string, mixed>
     */
    public static function queues(Chat $chat): array
    {
        $selected = $chat->subscriptions()->pluck('queue')->all();
        $buttons = array_map(
            fn (string $queue) => in_array($queue, $selected, true)
                ? self::inline("✅ {$queue}", "q-:{$queue}")
                : self::inline($queue, "q+:{$queue}"),
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

    /**
     * @param  list<Address>  $addresses
     * @return array<string, mixed>
     */
    public static function addresses(array $addresses): array
    {
        $rows = array_map(fn (Address $address) => [self::inline("🗑 {$address->street->name}, {$address->house}", "a-:{$address->id}")], $addresses);

        if (count($addresses) < (int) config('addresses.max_addresses')) {
            $rows[] = [self::inline('➕ Додати адресу', 'a+')];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param  list<Street>  $streets
     * @return array<string, mixed>
     */
    public static function addressDraft(string $step, array $streets, CityRegistry $cities): array
    {
        $rows = match ($step) {
            'city' => array_map(fn (string $code) => [self::inline($cities->name($code), "a+:{$code}")], $cities->codes()),
            'choose' => array_map(fn (Street $street) => [self::inline($street->name, "as:{$street->id}")], $streets),
            default => [],
        };

        return ['inline_keyboard' => [...$rows, [self::inline('Скасувати', 'address')]]];
    }
}
