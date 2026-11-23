<?php

namespace App\Schedule;

use Carbon\CarbonImmutable;

/** An outage at absolute time; may cross midnight. */
final readonly class Outage
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}
}
