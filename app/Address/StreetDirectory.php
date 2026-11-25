<?php

namespace App\Address;

use App\Sources\SourceException;

/** Names of streets and houses; says nothing about outages. */
interface StreetDirectory
{
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
