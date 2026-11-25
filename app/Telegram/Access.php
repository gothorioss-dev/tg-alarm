<?php

namespace App\Telegram;

use App\Models\Chat;
use Illuminate\Database\Eloquent\Builder;

/** The only place that decides what a user may do. */
final class Access
{
    /** Screens available before a queue is selected. */
    private const FREE = ['start', 'queue', 'help'];

    public function screen(Chat $chat, string $screen): bool
    {
        return in_array($screen, self::FREE, true)
            || ($screen === 'stats' && Keyboards::isAdmin($chat->telegram_id))
            || $chat->subscriptions()->exists();
    }

    /** Outages by address. */
    public function addresses(Chat $chat): bool
    {
        return $chat->subscriptions()->exists();
    }

    /**
     * The same rule for a query of chats.
     *
     * @param  Builder<Chat>  $chats
     * @return Builder<Chat>
     */
    public function withAddresses(Builder $chats): Builder
    {
        return $chats->whereHas('subscriptions');
    }
}
