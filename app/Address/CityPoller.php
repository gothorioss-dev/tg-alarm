<?php

namespace App\Address;

use App\Ingest\Outbox;
use App\Models\Address;
use App\Models\ChannelPost;
use App\Models\City;
use App\Models\CityEvent;
use App\Models\Street;
use App\Monitor\ProcessStatus;
use App\Sources\SourceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Keeps outages and streets of cities up to date. */
final class CityPoller
{
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
                $this->cleanUp();
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
            $this->registry->feed($city->code)->poll($city);
        } catch (SourceException $e) {
            $city->increment('failures');
            Log::warning("City {$city->code} failed: {$e->getMessage()}");

            return;
        }

        $city->update(['failures' => 0, 'succeeded_at' => now()]);
    }

    private function cleanUp(): void
    {
        $this->tracker->forget();

        $since = now()->subDays((int) config('addresses.keep_days'));
        CityEvent::query()->where('closed_at', '<', $since)->delete();
        ChannelPost::query()->whereNull('city_event_id')->where('status', '!=', ChannelPost::PENDING)->where('published_at', '<', $since)->delete();
    }

    private function streets(City $city): void
    {
        $city->update(['streets_polled_at' => now()]);

        try {
            $names = $this->registry->directory($city->code)->streets();
        } catch (Throwable $e) {
            Log::warning("Streets of {$city->code} failed: {$e->getMessage()}");
            // Asked for again soon.
            $city->update(['streets_polled_at' => now()->subSeconds((int) config('addresses.streets_interval'))->addSeconds((int) config('addresses.interval'))]);

            return;
        }

        $records = [];

        foreach ($names as $name) {
            $key = AddressText::normalize($name);
            $records[$key] ??= ['city_id' => $city->id, 'name' => $name, 'key' => $key, 'active' => true, 'listed' => true];
        }

        $active = Street::query()->where('city_id', $city->id)->where('active', true)->where('listed', true)->pluck('key', 'id')->all();

        if (count($records) < count($active) / 2) {
            Log::warning("Streets of {$city->code}: only ".count($records).' instead of '.count($active));

            return;
        }

        $gone = array_keys(array_filter($active, fn (string $key) => ! isset($records[$key])));

        DB::transaction(function () use ($records, $gone) {
            foreach (array_chunk(array_values($records), 200) as $chunk) {
                Street::query()->upsert($chunk, ['city_id', 'key'], ['name', 'active', 'listed']);
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
}
