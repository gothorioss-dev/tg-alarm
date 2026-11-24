<?php

namespace App\Monitor;

/** A detected problem; critical ones are reported with sound at any time. */
final readonly class Problem
{
    public function __construct(public string $key, public string $title, public string $details, public bool $critical = false) {}
}
