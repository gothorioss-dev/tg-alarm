<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\Delivery;

/** A group that becomes a supergroup gets a new id. */
final class ChatMigrator
{
    public static function migrate(int $from, int $to): void
    {
        Delivery::query()->where('telegram_id', $from)->where('status', Delivery::PENDING)->update(['telegram_id' => $to]);

        $old = Chat::query()->where('telegram_id', $from)->first();

        if ($old === null) {
            return;
        }

        // The bot may have already seen the new id without any settings.
        $existing = Chat::query()->where('telegram_id', $to)->first();

        if ($existing?->subscriptions()->exists()) {
            $old->delete();

            return;
        }

        $existing?->delete();
        $old->update(['telegram_id' => $to, 'type' => 'supergroup']);
    }
}
