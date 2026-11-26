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
            || ($screen === 'address' ? $this->addresses($chat) : $chat->subscriptions()->exists());
    }

    /** Outages by address. */
    public function addresses(Chat $chat): bool
    {
        return $this->perks($chat) && $chat->subscriptions()->exists();
    }

    /**
     * The same rule for a query of chats.
     *
     * @param  Builder<Chat>  $chats
     * @return Builder<Chat>
     */
    public function withAddresses(Builder $chats): Builder
    {
        return $chats->whereNotNull('perks_at')->whereHas('subscriptions');
    }

    /** Perks of the first users: outages by address for now. */
    public function perks(?Chat $chat): bool
    {
        return $chat?->perks_at !== null;
    }

    /** Gives the perks to one of the first users who have selected a queue. */
    public function admit(Chat $chat): void
    {
        if ($chat->perks_at === null && Chat::query()->whereNotNull('perks_at')->count() < (int) config('perks.limit')) {
            $chat->update(['perks_at' => now()]);
        }
    }
}
