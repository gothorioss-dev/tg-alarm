<?php

namespace App\Telegram;

use App\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;

/** Messages sent independently, each channel by its own process. */
enum DeliveryChannel: string
{
    /** Schedules and notices for everyone. */
    case Broadcast = 'broadcast';

    /** Reminders and service alerts, which must not wait for a broadcast. */
    case Urgent = 'urgent';

    /** @return Builder<Delivery> pending messages in the order of sending */
    public function pending(): Builder
    {
        $query = Delivery::query()->where('status', Delivery::PENDING);

        return match ($this) {
            self::Broadcast => $query->where('dedupe_key', 'not like', 'remind:%')->where('dedupe_key', 'not like', 'admin:%')->orderBy('id'),
            // Alerts never expire and go first, then the reminders about the nearest outages.
            self::Urgent => $query->where(fn (Builder $q) => $q->where('dedupe_key', 'like', 'remind:%')->orWhere('dedupe_key', 'like', 'admin:%'))
                ->orderBy('expires_at')->orderBy('id'),
        };
    }

    /** Messages per second. */
    public function rate(): int
    {
        return max(1, (int) config("telegram.send.rates.{$this->value}"));
    }
}
