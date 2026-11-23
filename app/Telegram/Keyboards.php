<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Schedule\Queues;

final class Keyboards
{
    public const SCHEDULE = '📅 Графік';

    public const QUEUES = '⚙️ Мої черги';

    public const STOP = '🔕 Вимкнути сповіщення';

    public const START = '🔔 Увімкнути сповіщення';

    /** Button label => action, for the private reply keyboard. */
    public const ACTIONS = [
        self::SCHEDULE => 'schedule',
        self::QUEUES => 'queue',
        self::STOP => 'stop',
        self::START => 'enable',
    ];

    /**
     * Bottom keyboard in private chats. Groups get inline buttons instead: in privacy mode
     * the bot does not receive plain text sent by a reply keyboard there.
     *
     * @return array<string, mixed>
     */
    public static function menu(bool $private, bool $active): array
    {
        $toggle = $active ? self::STOP : self::START;

        if ($private) {
            return [
                'keyboard' => [[['text' => self::SCHEDULE], ['text' => self::QUEUES]], [['text' => $toggle]]],
                'resize_keyboard' => true,
                'is_persistent' => true,
            ];
        }

        return ['inline_keyboard' => [
            [self::inline(self::SCHEDULE, 'schedule'), self::inline('⚙️ Черги', 'queue')],
            [self::inline($toggle, self::ACTIONS[$toggle])],
        ]];
    }

    /** @return array<string, mixed> */
    public static function queues(?Chat $chat): array
    {
        $selected = $chat?->subscriptions()->pluck('queue')->all() ?? [];
        $buttons = array_map(
            fn (string $queue) => self::inline((in_array($queue, $selected, true) ? '✅ ' : '').$queue, "q:{$queue}"),
            Queues::ALL,
        );

        return ['inline_keyboard' => [...array_chunk($buttons, 4), [self::inline('💾 Готово', 'done')]]];
    }

    /** @return array<string, mixed> */
    public static function scheduleOnly(): array
    {
        return ['inline_keyboard' => [[self::inline(self::SCHEDULE, 'schedule')]]];
    }

    /** @return array{text: string, callback_data: string} */
    private static function inline(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }
}
