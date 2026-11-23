<?php

namespace App\Ingest;

use App\Models\Chat;
use App\Schedule\OutageTimeline;

/** Queues reminders about outages that start soon. */
final class Reminders
{
    public function __construct(private Outbox $outbox) {}

    public function queueDue(): void
    {
        $chats = Chat::query()->where('active', true)->whereNotNull('remind_minutes')
            ->whereHas('subscriptions')->with('subscriptions')->get();

        if ($chats->isEmpty()) {
            return;
        }

        $timeline = OutageTimeline::load();

        foreach ($chats as $chat) {
            foreach ($chat->subscriptions as $subscription) {
                $outage = $timeline->next($subscription->queue);

                if ($outage && $outage->start <= $timeline->now()->addMinutes($chat->remind_minutes)) {
                    $this->outbox->reminder($chat, $subscription->queue, $outage, $timeline->now());
                }
            }
        }
    }
}
