<?php

namespace App\Address;

use Carbon\CarbonImmutable;

/** A street of an outage as listed in a file; the time is in UTC. */
final readonly class FileRow
{
    public function __construct(
        public string $street,
        public string $houses,
        public Kind $kind,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
    ) {}
}
