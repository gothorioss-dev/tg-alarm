<?php

namespace App\Ingest;

use App\Models\SourceState;
use App\Sources\FetchResult;
use App\Sources\SourceRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Poller
{
    public function __construct(
        private SourceRegistry $sources,
        private Ingestor $ingestor,
        private Outbox $outbox,
    ) {}

    public function pollDue(): void
    {
        /** @var array<string, FetchResult> $results */
        $results = [];

        foreach ($this->sources->keys() as $key) {
            $state = SourceState::query()->findOrNew($key);
            $state->source = $key;

            if ($state->polled_at && $state->polled_at->addSeconds($this->sources->interval($key))->isFuture()) {
                continue;
            }

            $state->polled_at = now();

            try {
                $results[$key] = $this->sources->make($key)->fetch($state->state ?? []);
                $state->save();
            } catch (Throwable $e) {
                $this->failed($state, $e);
            }
        }

        if ($results === []) {
            return;
        }

        $this->ingestor->ingest($results);

        foreach (array_keys($results) as $key) {
            $this->recovered($key);
        }
    }

    private function failed(SourceState $state, Throwable $e): void
    {
        $state->failures++;
        $state->save();
        Log::warning("Source {$state->source} failed: {$e->getMessage()}");

        if ($state->failures === (int) config('sources.alert_after_failures')) {
            $this->outbox->admin(
                "source-down:{$state->source}:".now()->timestamp,
                "Джерело «{$this->sources->label($state->source)}» недоступне або змінило формат: {$e->getMessage()}",
            );
        }
    }

    private function recovered(string $key): void
    {
        $state = SourceState::query()->findOrFail($key);

        if ($state->failures >= (int) config('sources.alert_after_failures')) {
            $this->outbox->admin("source-up:{$key}:".now()->timestamp, "Джерело «{$this->sources->label($key)}» знову працює.");
        }
        if ($state->failures > 0) {
            $state->update(['failures' => 0]);
        }
    }
}
