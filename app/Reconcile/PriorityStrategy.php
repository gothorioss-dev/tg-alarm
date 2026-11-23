<?php

namespace App\Reconcile;

use App\Models\PublicationRecord;
use App\Models\Schedule;
use App\Sources\SourceRegistry;

/** First source wins immediately; on disagreement about one version the higher priority wins. */
final class PriorityStrategy implements ReconcileStrategy
{
    public function __construct(private SourceRegistry $sources) {}

    public function decide(PublicationRecord $incoming, ?Schedule $current): Decision
    {
        if ($current === null) {
            return Decision::apply();
        }
        if ($incoming->schedule_hash === $current->day()->hash()) {
            return Decision::confirm();
        }

        $twin = $this->findTwin($incoming, $current);

        if ($twin !== null) {
            return Decision::ignore($twin);
        }

        $hasNewerOfSameSource = PublicationRecord::query()
            ->where('source', $incoming->source)
            ->where('schedule_date', $incoming->schedule_date)
            ->whereIn('status', [PublicationRecord::APPLIED, PublicationRecord::CONFIRMED, PublicationRecord::IGNORED, PublicationRecord::BASELINE])
            ->where('published_at', '>', $incoming->published_at)
            ->exists();

        if ($hasNewerOfSameSource) {
            return Decision::ignore();
        }
        if ($incoming->source === $current->source || $incoming->source === $current->publication->source) {
            // A confirmed version may still be corrected by the confirming source.
            return Decision::apply();
        }

        return match ($this->compareVersions($incoming, $current)) {
            1 => Decision::apply(),
            -1 => Decision::ignore(),
            default => $this->sources->priority($incoming->source) > $this->sources->priority($current->source)
                ? Decision::apply(correction: true)
                : Decision::ignore(),
        };
    }

    /** An unmatched copy of the same content published earlier by another source and already superseded. */
    private function findTwin(PublicationRecord $incoming, Schedule $current): ?PublicationRecord
    {
        $window = (int) config('sources.twin_window_minutes');

        return PublicationRecord::query()
            ->where('schedule_date', $incoming->schedule_date)
            ->where('schedule_hash', $incoming->schedule_hash)
            ->where('source', '!=', $incoming->source)
            ->whereNull('twin_id')
            ->where('id', '!=', $current->publication_id)
            ->where('published_at', '<', $current->publication->published_at)
            ->whereBetween('published_at', [
                $incoming->published_at->subMinutes($window),
                $incoming->published_at->addMinutes($window),
            ])
            ->orderBy('published_at')
            ->first();
    }

    /** 1 = incoming is newer, -1 = older, 0 = the same version. */
    private function compareVersions(PublicationRecord $incoming, Schedule $current): int
    {
        if ($incoming->declared_at !== null && $current->declared_at !== null) {
            return $incoming->declared_at <=> $current->declared_at;
        }

        // Publication times are comparable across sources; a declared time is only exact against another one.
        $minutes = $current->publication->published_at->diffInMinutes($incoming->published_at);

        if (abs($minutes) <= (int) config('sources.same_version_minutes')) {
            return 0;
        }

        return $minutes <=> 0;
    }
}
