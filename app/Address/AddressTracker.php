<?php

namespace App\Address;

use App\Ingest\Outbox;
use App\Models\Address;
use App\Models\AddressEvent;
use App\Models\City;
use App\Models\CityOutage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Compares outages of a city with what is known about the addresses of users. */
final class AddressTracker
{
    private const EMERGENCY = 'Аварійне відключення';

    public function __construct(private Outbox $outbox) {}

    /**
     * @param  list<OutageRow>  $rows
     * @param  bool  $trusted  whether a missing outage can be taken as ended
     */
    public function apply(City $city, array $rows, bool $trusted): void
    {
        $byStreet = [];

        foreach ($rows as $row) {
            $byStreet[AddressText::normalize($row->street)][] = $row;
        }

        $houses = [];

        // All addresses of a user go together, so that one outage is one message.
        Address::query()->where('city_id', $city->id)->distinct()->orderBy('chat_id')->pluck('chat_id')
            ->chunk(200)->each(function ($chatIds) use ($city, $byStreet, $trusted, &$houses) {
                $addresses = Address::query()->where('city_id', $city->id)->whereIn('chat_id', $chatIds)
                    ->with(['chat', 'street', 'city', 'events'])->orderBy('id')->get();

                foreach ($addresses->groupBy('chat_id') as $group) {
                    $plans = [];

                    foreach ($group as $address) {
                        $found = $this->search($address->house, $byStreet[$address->street->key] ?? [], $houses);

                        if ($plan = $this->plan($address, $found, $trusted)) {
                            $plans[] = [$address, $plan];
                        }
                    }

                    if ($plans !== []) {
                        $this->save($plans);
                    }
                }
            });

        AddressEvent::query()->whereNotNull('ended_at')->where('ended_at', '<', now()->subDays((int) config('addresses.keep_days')))->delete();
    }

    /** Remembers the current outages of a new address, which are shown to the user at once. */
    public function start(Address $address): void
    {
        $rows = CityOutage::query()->where('city_id', $address->city_id)->where('street_key', $address->street->key)->get()
            ->map(fn (CityOutage $row) => new OutageRow($address->street->name, $row->houses, $row->kind, $row->starts_at, $row->ends_at))->all();
        $houses = [];

        foreach ($this->search($address->house, $rows, $houses) as $found) {
            AddressEvent::query()->firstOrCreate(
                ['address_id' => $address->id, 'kind' => $found->kind, 'starts_at' => $found->startsAt],
                ['ends_at' => $found->endsAt, 'note' => $found->note ?: null, 'timed_at' => now()],
            );
        }
    }

    /**
     * @param  array<OutageRow>  $rows
     * @param  array<string, HouseList>  $houses  parsed lists
     * @return array<string, Found> by kind and start
     */
    private function search(string $house, array $rows, array &$houses): array
    {
        $found = [];

        foreach ($rows as $row) {
            $note = ($houses[$row->houses] ??= new HouseList($row->houses))->match($house);

            if ($note === null) {
                continue;
            }

            $key = $this->key($row->kind, $row->startsAt);
            $known = $found[$key] ?? null;
            $found[$key] = new Found(
                $row->kind,
                $row->startsAt,
                // An unknown end is the latest one.
                $known === null ? $row->endsAt : ($known->endsAt === null || $row->endsAt === null ? null : $known->endsAt->max($row->endsAt)),
                $known === null ? $note : ($known->note === '' || $note === '' ? '' : implode('; ', array_unique([$known->note, $note]))),
            );
        }

        ksort($found);

        return $found;
    }

    /**
     * @param  array<string, Found>  $found
     * @return list<array{AddressEvent, array<string, mixed>, ?Change, array<string, mixed>}> event, new values, what to tell and the previous values
     */
    private function plan(Address $address, array $found, bool $trusted): array
    {
        $now = CarbonImmutable::now();
        $events = $address->events->keyBy(fn (AddressEvent $event) => $this->key($event->kind, $event->starts_at));
        $active = $events->filter(fn (AddressEvent $event) => $event->ended_at === null);
        $pairs = [];

        foreach ($active as $key => $event) {
            if (isset($found[$key])) {
                $pairs[] = [$event, $found[$key]];
                unset($found[$key], $active[$key]);
            }
        }
        // What is left on both sides may be the same outage with another start.
        foreach ($active as $key => $event) {
            foreach ($found as $newKey => $new) {
                if (! isset($events[$newKey]) && $this->isMoved($event, $new, $now)) {
                    $pairs[] = [$event, $new];
                    unset($found[$newKey], $active[$key]);

                    break;
                }
            }
        }

        $plan = [];

        foreach ($pairs as [$event, $new]) {
            if ($change = $this->update($event, $new, $now)) {
                $plan[] = $change;
            }
        }
        foreach ($found as $key => $new) {
            $event = $events[$key] ?? new AddressEvent(['address_id' => $address->id, 'kind' => $new->kind, 'revision' => 0]);
            $isOver = $new->endsAt !== null && $new->endsAt <= $now;
            $plan[] = [$event, [
                'starts_at' => $new->startsAt, 'ends_at' => $new->endsAt, 'note' => $new->note ?: null, 'missing' => 0, 'ended_at' => null,
                'timed_at' => $now, 'reminded_day_at' => null, 'reminded_at' => null, 'revision' => $event->revision + 1,
            ], $isOver ? null : Change::Appeared, []];
        }

        foreach ($trusted ? $active : [] as $event) {
            $missing = $event->missing + 1;

            if ($missing < (int) config('addresses.missing_polls')) {
                $plan[] = [$event, ['missing' => $missing], null, []];

                continue;
            }

            $started = $event->starts_at <= $now;
            $plan[] = [$event, ['missing' => $missing, 'ended_at' => $now, 'revision' => $event->revision + 1], match (true) {
                ! $started => Change::Cancelled,
                $this->isForgotten($event, $now) => null,
                default => Change::Ended,
            }, []];
        }

        return $plan;
    }

    /** @return array{AddressEvent, array<string, mixed>, ?Change, array<string, mixed>}|null */
    private function update(AddressEvent $event, Found $new, CarbonImmutable $now): ?array
    {
        $moved = ! $event->starts_at->equalTo($new->startsAt);
        $retimed = $moved || $event->ends_at?->getTimestamp() !== $new->endsAt?->getTimestamp();
        $values = [];

        if ($event->missing > 0) {
            $values['missing'] = 0;
        }
        if (($event->note ?? '') !== $new->note) {
            $values['note'] = $new->note ?: null;
        }
        if ($retimed) {
            $values += ['starts_at' => $new->startsAt, 'ends_at' => $new->endsAt, 'revision' => $event->revision + 1];
        }
        if ($moved) {
            $values += ['timed_at' => $now, 'reminded_day_at' => null, 'reminded_at' => null];
        }

        return $values === [] ? null : [$event, $values, $retimed ? Change::Changed : null, ['starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]];
    }

    private function isMoved(AddressEvent $event, Found $new, CarbonImmutable $now): bool
    {
        if ($event->kind !== $new->kind) {
            return false;
        }

        $timezone = config('telegram.timezone');

        return abs($event->starts_at->getTimestamp() - $new->startsAt->getTimestamp()) <= 3600
            || ($event->starts_at > $now && $new->startsAt > $now
                && $event->starts_at->setTimezone($timezone)->isSameDay($new->startsAt->setTimezone($timezone)));
    }

    /** Scheduled works stay in the list for hours after their end: that is not news. */
    private function isForgotten(AddressEvent $event, CarbonImmutable $now): bool
    {
        return $event->kind !== self::EMERGENCY && $event->ends_at !== null
            && $event->ends_at->addHours((int) config('addresses.silent_end_hours')) < $now;
    }

    /** @param list<array{Address, list<array{AddressEvent, array<string, mixed>, ?Change, array<string, mixed>}>}> $plans plans of one user */
    private function save(array $plans): void
    {
        DB::transaction(function () use ($plans) {
            $messages = [];

            foreach ($plans as [$address, $plan]) {
                // The write goes first: it locks the database and shows that the address still exists.
                if (Address::query()->whereKey($address->id)->update(['updated_at' => now()]) === 0) {
                    continue;
                }

                foreach ($plan as [$event, $values, $change, $old]) {
                    if ($change === Change::Changed || $change === Change::Cancelled) {
                        $this->outbox->forgetAddressReminders($event->id, $event->revision);
                    }
                    // The bot may have added the same outage meanwhile and shown it.
                    if (! $event->exists && AddressEvent::query()->where('address_id', $address->id)->where('kind', $event->kind)->where('starts_at', $values['starts_at'])->exists()) {
                        continue;
                    }

                    $event->fill($values)->save();

                    if ($change !== null) {
                        $key = implode('|', [$change->value, $event->kind, $event->starts_at, $event->ends_at, $old['starts_at'] ?? '', $old['ends_at'] ?? '']);
                        $messages[$key] ??= ['change' => $change, 'old' => $old, 'items' => []];
                        $messages[$key]['items'][] = [$address, $event];
                    }
                }
            }

            $chat = $plans[0][0]->chat;

            foreach ($chat->active ? $messages : [] as $message) {
                $this->outbox->address($chat, $message['change'], $message['items'], $message['old']);
            }
        });
    }

    private function key(string $kind, CarbonImmutable $start): string
    {
        return $start->getTimestamp().'|'.$kind;
    }
}
