<?php

namespace App\Sources;

final readonly class FetchResult
{
    /**
     * @param  list<Publication>  $publications
     * @param  array<string, mixed>  $state  source cursor, saved only after successful processing
     */
    public function __construct(public array $publications, public array $state) {}
}
