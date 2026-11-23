<?php

namespace App\Telegram;

use App\Models\Delivery;
use Carbon\CarbonImmutable;

/** Removes outdated records of the delivery log. */
final class DeliveryCleanup
{
    private ?CarbonImmutable $nextRun = null;

    public function runDue(): void
    {
        if ($this->nextRun?->isFuture()) {
            return;
        }

        $this->nextRun = CarbonImmutable::now()->addHour();

        // Pending messages are kept: they still have to be sent or expire.
        Delivery::query()->where('status', '!=', Delivery::PENDING)
            ->where('created_at', '<', now()->subDays((int) config('telegram.deliveries_keep_days')))
            ->delete();
    }
}
