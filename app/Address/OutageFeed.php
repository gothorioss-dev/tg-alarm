<?php

namespace App\Address;

use App\Models\City;
use App\Sources\SourceException;

/** Source of outages of a city. */
interface OutageFeed
{
    /**
     * Reads what is new and brings outages up to date.
     *
     * @throws SourceException
     */
    public function poll(City $city): void;
}
