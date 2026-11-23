<?php

namespace App\Sources;

use Carbon\CarbonImmutable;

/** A raw post of any source, reduced to the common form. */
final readonly class Publication
{
    public function __construct(
        public string $externalId,
        public string $title,
        /** Plain text with line breaks, including the title. */
        public string $text,
        public string $url,
        public CarbonImmutable $publishedAt,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->text);
    }
}
