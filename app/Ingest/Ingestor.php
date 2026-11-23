<?php

namespace App\Ingest;

use App\Models\PublicationRecord;
use App\Models\Schedule;
use App\Models\SourceState;
use App\Reconcile\ReconcileStrategy;
use App\Schedule\DaySchedule;
use App\Schedule\Kind;
use App\Schedule\ScheduleFormatException;
use App\Schedule\ScheduleParser;
use App\Sources\FetchResult;
use App\Sources\Publication;
use App\Sources\SourceRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Stores new publications, updates current schedules and queues notifications in one
 * transaction, so a crash neither loses nor duplicates a change.
 */
final class Ingestor
{
    /** @var array<string, DaySchedule|null> schedules by date before this run */
    private array $before = [];

    /** @var array<string, bool> dates to notify about; value = the change is a correction */
    private array $changed = [];

    public function __construct(
        private SourceRegistry $sources,
        private ScheduleParser $parser,
        private ReconcileStrategy $strategy,
        private Outbox $outbox,
    ) {}

    /** @param array<string, FetchResult> $results by source key */
    public function ingest(array $results): void
    {
        $this->before = $this->changed = [];

        DB::transaction(function () use ($results) {
            $items = [];
            $initialized = [];

            foreach ($results as $source => $result) {
                $initialized[$source] = (bool) SourceState::query()->find($source)?->initialized;

                foreach ($result->publications as $publication) {
                    $items[] = [$source, $publication];
                }
            }

            // Chronological order over all sources; on a tie the higher priority goes first.
            usort($items, fn (array $a, array $b) => [$a[1]->publishedAt, -$this->sources->priority($a[0])]
                <=> [$b[1]->publishedAt, -$this->sources->priority($b[0])]);

            foreach ($items as [$source, $publication]) {
                $this->process($source, $publication, baseline: ! $initialized[$source]);
            }

            $this->notify();

            foreach ($results as $source => $result) {
                SourceState::query()->updateOrCreate(['source' => $source], [
                    'state' => $result->state,
                    'initialized' => true,
                    'succeeded_at' => now(),
                ]);
            }
        });
    }

    private function process(string $source, Publication $publication, bool $baseline): void
    {
        // Compared with the latest version only: returning to earlier content is a new event.
        $latest = PublicationRecord::query()->where('source', $source)
            ->where('external_id', $publication->externalId)->latest('id')->first();

        if ($latest?->content_hash === $publication->hash()) {
            return;
        }

        $edited = $latest !== null;
        $kind = $this->parser->classify($publication->text);

        if ($kind === Kind::Other) {
            return;
        }

        $record = new PublicationRecord([
            'source' => $source,
            'external_id' => $publication->externalId,
            'content_hash' => $publication->hash(),
            'kind' => $kind->value,
            'status' => PublicationRecord::BASELINE,
            'title' => $publication->title,
            'url' => $publication->url,
            'published_at' => $publication->publishedAt,
            'fetched_at' => now(),
        ]);

        match ($kind) {
            Kind::Schedule => $this->schedule($record, $publication, $baseline),
            Kind::Emergency => $this->emergency($record, $publication, $baseline || $edited),
            default => $this->unknown($record, $publication, $baseline || $edited),
        };
    }

    private function schedule(PublicationRecord $record, Publication $publication, bool $baseline): void
    {
        if (! $this->sources->provides($record->source, Kind::Schedule)) {
            return;
        }

        try {
            $parsed = $this->parser->parse($publication->text, $publication->publishedAt);
        } catch (ScheduleFormatException $e) {
            $record->status = PublicationRecord::REJECTED;
            $record->save();

            if (! $baseline) {
                $this->outbox->admin("parse:{$record->id}", "Графік не розпізнано: {$e->getMessage()}\n{$publication->url}");
            }

            return;
        }

        $record->fill([
            'schedule_date' => $parsed->date,
            'schedule_hash' => $parsed->schedule->hash(),
            'queues' => $parsed->schedule->toArray(),
            'declared_at' => $parsed->declaredAt,
        ]);

        $current = Schedule::query()->with('publication')->find($parsed->date);

        // The first poll of a source fills the state silently. While another source already
        // provides the date, its backlog is only recorded.
        if ($baseline) {
            $record->save();

            if ($current === null || $current->publication->source === $record->source) {
                $this->setCurrent($parsed->date, $record);
            }

            return;
        }

        $decision = $this->strategy->decide($record, $current);
        $record->status = $decision->status;
        $record->twin_id = $decision->twin?->id;
        $record->save();
        $decision->twin?->update(['twin_id' => $record->id]);

        if ($decision->status === PublicationRecord::CONFIRMED) {
            $this->confirm($current, $record);
        }
        if ($decision->status === PublicationRecord::APPLIED) {
            if (! array_key_exists($parsed->date, $this->before)) {
                $this->before[$parsed->date] = $current?->day();
            }
            $this->changed[$parsed->date] = $decision->correction;
            $this->setCurrent($parsed->date, $record);
        }
    }

    private function confirm(Schedule $current, PublicationRecord $record): void
    {
        $publication = $current->publication;

        if ($publication->source !== $record->source && $publication->twin_id === null) {
            $publication->update(['twin_id' => $record->id]);
            $record->update(['twin_id' => $publication->id]);
        }
        if ($this->sources->priority($record->source) > $this->sources->priority($current->source)) {
            $current->source = $record->source;
        }

        $current->declared_at ??= $record->declared_at;
        $current->save();
    }

    private function setCurrent(string $date, PublicationRecord $record): void
    {
        Schedule::query()->updateOrCreate(['date' => $date], [
            'publication_id' => $record->id,
            'source' => $record->source,
            'declared_at' => $record->declared_at,
            'queues' => $record->queues,
        ]);
    }

    private function emergency(PublicationRecord $record, Publication $publication, bool $silent): void
    {
        if (! $this->sources->provides($record->source, Kind::Emergency)) {
            return;
        }

        $record->status = $silent || ! $this->isFresh($publication) ? PublicationRecord::IGNORED : PublicationRecord::APPLIED;
        $record->save();

        if ($record->status === PublicationRecord::APPLIED) {
            $this->outbox->emergency($record->id, $publication->text, $record->source, $publication->url);
        }
    }

    /** The text is passed on as is; the stored schedule is not changed by a guess. */
    private function unknown(PublicationRecord $record, Publication $publication, bool $silent): void
    {
        $record->status = PublicationRecord::REJECTED;
        $record->save();

        if ($silent) {
            return;
        }

        $this->outbox->admin("unknown:{$record->id}", "Публікація про ГПВ без графіка: {$publication->title}\n{$publication->url}");

        if ($this->sources->provides($record->source, Kind::Unknown) && $this->isFresh($publication)) {
            $this->outbox->notice($record->id, $publication->text, $record->source, $publication->url);
        }
    }

    private function isFresh(Publication $publication): bool
    {
        return $publication->publishedAt->addMinutes((int) config('sources.emergency_max_age_minutes'))->isFuture();
    }

    /** One message per date, even if several versions were applied in this run. */
    private function notify(): void
    {
        $today = now(config('telegram.timezone'))->format('Y-m-d');

        foreach ($this->changed as $date => $correction) {
            if ($date < $today) {
                continue;
            }

            $current = Schedule::query()->with('publication')->findOrFail($date);
            $this->outbox->schedule(
                $date,
                $this->before[$date],
                $current->day(),
                $correction,
                $current->publication_id,
                $current->source,
                $current->publication->url,
                $current->publication->published_at,
            );
        }
    }
}
