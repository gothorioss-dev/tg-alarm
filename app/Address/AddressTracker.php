<?php

namespace App\Address;

use App\Ingest\Outbox;
use App\Models\Address;
use App\Models\AddressEvent;
use App\Models\CityEvent;
use App\Models\CityEventRow;
use App\Telegram\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Brings what users know about outages at their addresses in line with the source. */
final class AddressTracker
{
    /** @var array<string, HouseList> parsed lists */
    private array $houses = [];

    public function __construct(private Outbox $outbox, private Access $access) {}

    /**
     * Called within a transaction that has already written something.
     *
     * @param  bool  $notify  whether users are told
     * @param  bool  $started  an announced outage has been confirmed
     * @param  bool  $late  the news about the beginning is too old to be sent
     */
    public function apply(CityEvent $event, bool $notify, bool $started = false, bool $late = false): void
    {
        $this->houses = [];
        $rows = $event->closed_at !== null ? [] : $event->rows()->get()->groupBy('street_id')->all();

        Address::query()
            ->where(fn (Builder $query) => $query->whereIn('street_id', array_keys($rows))
                ->orWhereHas('events', fn (Builder $events) => $events->where('city_event_id', $event->id)->whereNull('ended_at')))
            ->with(['chat', 'street', 'city', 'events' => fn ($events) => $events->where('city_event_id', $event->id)])
            ->orderBy('chat_id')->orderBy('id')->get()->groupBy('chat_id')
            // All addresses of a user go together, so that one outage is one message.
            ->each(function ($addresses) use ($event, $rows, $notify, $started, $late) {
                $messages = [];

                foreach ($addresses as $address) {
                    $found = $this->search($address->house, $rows[$address->street_id] ?? []);
                    $result = $this->update($event, $address, $found, $started, $late);

                    if ($result !== null) {
                        [$change, $addressEvent, $old] = $result;
                        $key = implode('|', [$change->value, $addressEvent->kind, $addressEvent->starts_at, $addressEvent->ends_at, $old['starts_at'] ?? '', $old['ends_at'] ?? '', $old['kind'] ?? '']);
                        $messages[$key] ??= ['change' => $change, 'old' => $old, 'items' => []];
                        $messages[$key]['items'][] = [$address, $addressEvent];
                    }
                }

                $chat = $addresses[0]->chat;

                foreach ($notify && $chat->active && $messages !== [] && $this->access->addresses($chat) ? $messages : [] as $message) {
                    $this->outbox->address($chat, $message['change'], $message['items'], $message['old']);
                }
            });
    }

    /** Remembers the current outages of a new address, which are shown to the user at once. */
    public function start(Address $address): void
    {
        $this->houses = [];
        $rows = CityEventRow::query()->where('street_id', $address->street_id)
            ->whereIn('city_event_id', CityEvent::query()->whereNull('closed_at')->select('id'))->get()->groupBy('city_event_id');
        $events = CityEvent::query()->whereKey($rows->keys())->get()->keyBy('id');

        foreach ($rows as $eventId => $eventRows) {
            if (($found = $this->search($address->house, $eventRows)) !== null) {
                AddressEvent::query()->firstOrCreate(['address_id' => $address->id, 'city_event_id' => $eventId], [
                    'kind' => $found->kind, 'starts_at' => $found->startsAt, 'ends_at' => $found->endsAt, 'note' => $found->note ?: null,
                    'announced' => $events[$eventId]->announced, 'timed_at' => now(),
                ]);
            }
        }
    }

    /** Closes outages about which there is no news any more. */
    public function forget(): void
    {
        foreach (['announced' => true, 'confirmed' => false] as $type => $announced) {
            $ids = CityEvent::query()->whereNull('closed_at')->where('announced', $announced)
                ->where('ends_at', '<', now()->subHours((int) config("addresses.forget_hours.{$type}")))->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::transaction(function () use ($ids) {
                    CityEvent::query()->whereKey($ids)->update(['closed_at' => now()]);
                    AddressEvent::query()->whereIn('city_event_id', $ids)->whereNull('ended_at')->update(['ended_at' => now()]);
                });
            }
        }
    }

    /** @return array{Change, AddressEvent, array{starts_at?: CarbonImmutable, ends_at?: ?CarbonImmutable, kind?: string}}|null */
    private function update(CityEvent $event, Address $address, ?Found $found, bool $started, bool $late): ?array
    {
        $now = CarbonImmutable::now();
        $known = $address->events->first();
        $isOpen = $known !== null && $known->ended_at === null;

        if ($found === null) {
            if (! $isOpen) {
                return null;
            }

            $this->outbox->forgetAddressReminders($known->id, $known->revision);
            $change = $known->starts_at > $now && ! $started ? Change::Cancelled : Change::Ended;
            $known->update(['ended_at' => $now, 'revision' => $known->revision + 1]);

            return [$change, $known, []];
        }

        $values = ['kind' => $found->kind, 'starts_at' => $found->startsAt, 'ends_at' => $found->endsAt, 'note' => $found->note ?: null, 'announced' => $event->announced];

        if (! $isOpen) {
            $known ??= new AddressEvent(['address_id' => $address->id, 'city_event_id' => $event->id, 'revision' => 0]);
            $known->fill([...$values, 'revision' => $known->revision + 1, 'timed_at' => $now, 'reminded_day_at' => null, 'reminded_at' => null, 'ended_at' => null])->save();

            return $late || ($found->endsAt !== null && $found->endsAt <= $now) ? null : [Change::Appeared, $known, []];
        }

        $old = ['starts_at' => $known->starts_at, 'ends_at' => $known->ends_at];
        $moved = ! $known->starts_at->equalTo($found->startsAt);
        $retimed = $moved || $known->ends_at?->getTimestamp() !== $found->endsAt?->getTimestamp();

        // An urgent repair can turn out to be an emergency.
        if ($known->kind !== $found->kind) {
            $old['kind'] = $known->kind;
            $retimed = true;
        }
        if ($started || $retimed) {
            $this->outbox->forgetAddressReminders($known->id, $known->revision);
            $values['revision'] = $known->revision + 1;
        }
        if ($moved) {
            $values += ['timed_at' => $now, 'reminded_day_at' => null, 'reminded_at' => null];
        }

        $known->update($values);

        return match (true) {
            $started && ! $late => [Change::Started, $known, $old],
            $retimed => [Change::Changed, $known, $old],
            default => null,
        };
    }

    /** @param iterable<CityEventRow> $rows of one outage on one street */
    private function search(string $house, iterable $rows): ?Found
    {
        $found = null;

        foreach ($rows as $row) {
            $note = ($this->houses[$row->houses] ??= new HouseList($row->houses))->match($house);

            if ($note === null) {
                continue;
            }

            $found = $found === null ? new Found($row->kind, $row->starts_at, $row->ends_at, $note) : new Found(
                $found->kind,
                $found->startsAt->min($row->starts_at),
                // An unknown end is the latest one.
                $found->endsAt === null || $row->ends_at === null ? null : $found->endsAt->max($row->ends_at),
                $found->note === '' || $note === '' ? '' : implode('; ', array_unique([$found->note, $note])),
            );
        }

        return $found;
    }
}
