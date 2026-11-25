<?php

namespace App\Address;

use Carbon\CarbonImmutable;

/** An outage on one street; the time is in UTC. */
final readonly class OutageRow
{
    public function __construct(
        public string $street,
        public string $houses,
        public string $kind,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
    ) {}
}
