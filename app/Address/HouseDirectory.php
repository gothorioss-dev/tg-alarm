<?php

namespace App\Address;

use App\Models\Address;
use App\Models\Street;
use App\Sources\SourceException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/** Houses known to the directory; the list is incomplete. */
final class HouseDirectory
{
    private const RETRY = 600;

    /** @var array<int, int> time of the next attempt by street id */
    private array $retryAt = [];

    public function __construct(private CityRegistry $registry) {}

    /** @return bool|null null when the list has not been loaded */
    public function knows(Street $street, string $house): ?bool
    {
        return $street->houses === null || $street->houses === [] ? null : (new HouseList(implode('|', $street->houses)))->match($house) !== null;
    }

    /** Checks new addresses, one street at a time. */
    public function checkDue(): void
    {
        $now = now()->getTimestamp();
        $this->retryAt = array_filter($this->retryAt, fn (int $time) => $time > $now);
        $street = Street::query()->whereNotIn('id', array_keys($this->retryAt))
            // An empty list says nothing about houses.
            ->where(fn (Builder $query) => $query->whereNull('houses_at')->orWhere('houses', '!=', '[]'))
            ->whereIn('id', Address::query()->whereNull('house_known')->select('street_id'))
            ->with('city')->orderBy('id')->first();

        if ($street === null || ! $this->load($street)) {
            return;
        }

        foreach (Address::query()->where('street_id', $street->id)->whereNull('house_known')->get() as $address) {
            $address->update(['house_known' => $this->knows($street, $address->house)]);
        }
    }

    private function load(Street $street): bool
    {
        if ($street->houses_at !== null && $street->houses_at->addDays((int) config('addresses.houses_keep_days'))->isFuture()) {
            return true;
        }

        try {
            $street->update(['houses' => $this->registry->directory($street->city->code)->houses($street->name), 'houses_at' => now()]);

            return true;
        } catch (SourceException $e) {
            Log::warning("Houses are not available: {$e->getMessage()}");
            $this->retryAt[$street->id] = now()->getTimestamp() + self::RETRY;

            return false;
        }
    }
}
