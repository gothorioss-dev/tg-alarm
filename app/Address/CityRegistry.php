<?php

namespace App\Address;

use App\Models\City;

final class CityRegistry
{
    /** @return list<string> codes of enabled cities */
    public function codes(): array
    {
        return array_keys(array_filter(config('addresses.cities'), fn (array $city) => $city['enabled'] ?? true));
    }

    public function make(string $code): CitySource
    {
        $city = config("addresses.cities.{$code}");

        return app()->make($city['class'], $city['options'] ?? []);
    }

    public function name(string $code): string
    {
        return (string) config("addresses.cities.{$code}.name", $code);
    }

    public function page(string $code): string
    {
        return (string) config("addresses.cities.{$code}.page", '');
    }

    /** Name of the source shown to users. */
    public function label(string $code): string
    {
        return (string) config("addresses.cities.{$code}.label", $code);
    }

    /** @return list<City> enabled cities */
    public function cities(): array
    {
        return array_map(fn (string $code) => City::query()->firstOrCreate(['code' => $code]), $this->codes());
    }
}
