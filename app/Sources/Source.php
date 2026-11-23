<?php

namespace App\Sources;

/** Implement this and add an entry to config/sources.php to plug in a new source. */
interface Source
{
    /**
     * @param  array<string, mixed>  $state  state returned by the previous fetch
     *
     * @throws SourceException
     */
    public function fetch(array $state): FetchResult;
}
