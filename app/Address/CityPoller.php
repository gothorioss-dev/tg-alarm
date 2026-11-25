<?php

namespace App\Address;

use App\Ingest\Outbox;
use App\Models\Address;
use App\Models\City;
use App\Models\CityOutage;
use App\Models\Street;
use App\Monitor\ProcessStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Keeps outages and streets of cities up to date. */
final class CityPoller
{
    /** @var array<string, true> house numbers already reported to the log */
    private array $logged = [];

    public function __construct(private CityRegistry $registry, private AddressTracker $tracker, private Outbox $outbox, private ProcessStatus $status) {}

    public function pollDue(): void
    {
        foreach ($this->registry->cities() as $city) {
            $this->status->beat();

            if ($this->isDue($city->streets_polled_at, (int) config('addresses.streets_interval'))) {
                $this->streets($city);
                $this->status->beat();
            }
            if ($this->isDue($city->polled_at, (int) config('addresses.interval'))) {
                $this->outages($city);
            }
        }
    }

    private function isDue(?\DateTimeInterface $last, int $seconds): bool
    {
        return $last === null || now()->getTimestamp() - $last->getTimestamp() >= $seconds;
    }

    private function outages(City $city): void
    {
        $city->update(['polled_at' => now()]);

        try {
            $rows = $this->registry->make($city->code)->outages();
        } catch (Throwable $e) {
            $city->increment('failures');
            Log::warning("City {$city->code} failed: {$e->getMessage()}");

            return;
        }

        $trusted = $this->isTrusted($city, count($rows));
        $this->tracker->apply($city, $rows, $trusted);

        if ($trusted) {
            $this->store($city, $rows);
        }

        $city->update(['failures' => 0, 'succeeded_at' => now()]);
        $this->logUnknown($rows);
    }

    /** A response that is much shorter than the previous one may be incomplete. */
    private function isTrusted(City $city, int $count): bool
    {
        $previous = CityOutage::query()->where('city_id', $city->id)->count();
        $shrunk = $previous >= (int) config('addresses.shrink.from') && $count < $previous * (float) config('addresses.shrink.share');
        $polls = $shrunk ? $city->shrunk + 1 : 0;
        $trusted = $polls < 1 || $polls >= (int) config('addresses.shrink.polls');

        if ($polls === 1) {
            Log::warning("City {$city->code}: only {$count} rows instead of {$previous}");
        }

        $city->update(['shrunk' => $trusted ? 0 : $polls]);

        return $trusted;
    }

    /** @param list<OutageRow> $rows */
    private function store(City $city, array $rows): void
    {
        $records = array_map(fn (OutageRow $row) => [
            'city_id' => $city->id,
            'street_key' => AddressText::normalize($row->street),
            'kind' => $row->kind,
            'houses' => $row->houses,
            'starts_at' => $row->startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $row->endsAt?->format('Y-m-d H:i:s'),
        ], $rows);
        // The order of rows changes from one response to another.
        sort($records);
        $hash = hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));

        if ($hash === $city->snapshot_hash) {
            return;
        }

        DB::transaction(function () use ($city, $records, $hash) {
            CityOutage::query()->where('city_id', $city->id)->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                CityOutage::query()->insert($chunk);
            }

            $city->update(['snapshot_hash' => $hash]);
        });
    }

    private function streets(City $city): void
    {
        $city->update(['streets_polled_at' => now()]);

        try {
            $names = $this->registry->make($city->code)->streets();
        } catch (Throwable $e) {
            Log::warning("Streets of {$city->code} failed: {$e->getMessage()}");
            // Asked for again soon.
            $city->update(['streets_polled_at' => now()->subSeconds((int) config('addresses.streets_interval'))->addSeconds((int) config('addresses.interval'))]);

            return;
        }

        $records = [];

        foreach ($names as $name) {
            $key = AddressText::normalize($name);
            $records[$key] ??= ['city_id' => $city->id, 'name' => $name, 'key' => $key, 'active' => true];
        }

        $active = Street::query()->where('city_id', $city->id)->where('active', true)->pluck('key', 'id')->all();

        if (count($records) < count($active) / 2) {
            Log::warning("Streets of {$city->code}: only ".count($records).' instead of '.count($active));

            return;
        }

        $gone = array_keys(array_filter($active, fn (string $key) => ! isset($records[$key])));

        DB::transaction(function () use ($records, $gone) {
            foreach (array_chunk(array_values($records), 200) as $chunk) {
                Street::query()->upsert($chunk, ['city_id', 'key'], ['name', 'active']);
            }
            foreach (array_chunk($gone, 500) as $ids) {
                Street::query()->whereKey($ids)->update(['active' => false]);
            }
        });

        $used = Street::query()->whereKey($gone)->whereIn('id', Address::query()->select('street_id'))->pluck('name')->all();

        if ($used !== []) {
            $this->outbox->admin("streets-gone:{$city->code}:".now()->timestamp, 'Зі списку вулиць зникли ті, за якими стежать люди: '.implode(', ', $used));
        }
    }

    /** @param list<OutageRow> $rows */
    private function logUnknown(array $rows): void
    {
        foreach ($rows as $row) {
            foreach ((new HouseList($row->houses))->unknown() as $part) {
                if (! isset($this->logged[$part]) && count($this->logged) < 500) {
                    $this->logged[$part] = true;
                    Log::info("House is not recognized: {$row->street}: {$part}");
                }
            }
        }
    }
}
