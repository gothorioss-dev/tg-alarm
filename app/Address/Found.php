<?php

namespace App\Address;

use Carbon\CarbonImmutable;

/** An outage found at an address. */
final readonly class Found
{
    public function __construct(
        public string $kind,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public string $note,
    ) {}
}
