<?php

namespace App\Reconcile;

use App\Models\PublicationRecord;

final readonly class Decision
{
    private function __construct(
        public string $status,
        public bool $correction = false,
        public ?PublicationRecord $twin = null,
    ) {}

    /** Becomes the current schedule. */
    public static function apply(bool $correction = false): self
    {
        return new self(PublicationRecord::APPLIED, $correction);
    }

    /** Same content as the current schedule. */
    public static function confirm(): self
    {
        return new self(PublicationRecord::CONFIRMED);
    }

    /** Outdated or overridden; an earlier copy may be given as the twin. */
    public static function ignore(?PublicationRecord $twin = null): self
    {
        return new self(PublicationRecord::IGNORED, twin: $twin);
    }
}
