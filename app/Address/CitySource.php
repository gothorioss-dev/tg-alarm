<?php

namespace App\Address;

use App\Sources\SourceException;

interface CitySource
{
    /**
     * @return list<OutageRow> all current and planned outages
     *
     * @throws SourceException
     */
    public function outages(): array;

    /**
     * @return list<string>
     *
     * @throws SourceException
     */
    public function streets(): array;

    /**
     * @return list<string>
     *
     * @throws SourceException
     */
    public function houses(string $street): array;
}
