<?php

namespace App\Address;

use App\Models\City;
use App\Models\Street;
use Illuminate\Support\Facades\Log;

/** Finds a street of an outage in the directory; an unknown one is added. */
final class StreetResolver
{
    public function __construct(private StreetSearch $search) {}

    public function resolve(City $city, string $name): Street
    {
        $key = AddressText::normalize($name);
        $street = Street::query()->where('city_id', $city->id)->where('key', $key)->first();

        if ($street !== null && ($street->listed || ! $this->search->same($city, $name))) {
            return $street;
        }
        if (($same = $this->search->same($city, $name)) !== null) {
            return $same;
        }

        Log::info("Street is added from an outage: {$name}");

        return Street::query()->create(['city_id' => $city->id, 'name' => trim($name), 'key' => $key, 'listed' => false]);
    }
}
