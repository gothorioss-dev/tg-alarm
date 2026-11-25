<?php

namespace App\Address;

use App\Ingest\Outbox;
use App\Models\Address;
use App\Models\AddressEvent;
use App\Models\Chat;
use App\Telegram\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Queues reminders about outages known in advance: a day before and as set by the user. */
final class AddressReminders
{
    public function __construct(private Outbox $outbox, private Access $access) {}

    public function queueDue(): void
    {
        $now = CarbonImmutable::now();
        $day = (int) config('addresses.remind_day_minutes');
        $events = AddressEvent::query()->whereNull('ended_at')
            ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->addMinutes($day))
            ->where(fn ($query) => $query->whereNull('reminded_day_at')->orWhereNull('reminded_at'))
            ->whereHas('address.chat', fn ($query) => $this->access->withAddresses($query->where('active', true)->whereNotNull('remind_minutes')))
            ->with(['address.chat', 'address.street', 'address.city'])->orderBy('id')->get();
        $due = [];

        foreach ($events as $event) {
            $chat = $event->address->chat;

            // After a downtime a late reminder "in a day" is of no use.
            foreach (['reminded_day_at' => [$day, 120], 'reminded_at' => [$chat->remind_minutes, $chat->remind_minutes]] as $mark => [$lead, $window]) {
                $at = $event->starts_at->subMinutes($lead);

                if ($event->{$mark} === null && $event->timed_at <= $at && $at <= $now && $now < $at->addMinutes($window)) {
                    $key = implode('|', [$chat->id, $mark, $event->kind, $event->starts_at, $event->ends_at]);
                    $due[$key] ??= ['chat' => $chat, 'mark' => $mark, 'events' => []];
                    $due[$key]['events'][] = $event;
                }
            }
        }

        foreach ($due as $reminder) {
            $this->queue($reminder['chat'], $reminder['mark'], $reminder['events'], $now);
        }
    }

    /** @param non-empty-list<AddressEvent> $events the same outage at different addresses */
    private function queue(Chat $chat, string $mark, array $events, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($chat, $mark, $events, $now) {
            // The time could have changed since the events were read.
            $events = array_values(array_filter($events, fn (AddressEvent $event) => AddressEvent::query()->whereKey($event->id)
                ->where('revision', $event->revision)->whereNull('ended_at')->whereNull($mark)->update([$mark => $now]) === 1));

            if ($events !== []) {
                /** @var list<array{Address, AddressEvent}> $items */
                $items = array_map(fn (AddressEvent $event) => [$event->address, $event], $events);
                $this->outbox->addressReminder($chat, $mark, $items, $now);
            }
        });
    }
}
