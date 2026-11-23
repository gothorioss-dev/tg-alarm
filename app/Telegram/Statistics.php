<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\SourceState;
use App\Models\Subscription;
use App\Sources\SourceRegistry;

/** Aggregated numbers for the admin, without personal data. */
final class Statistics
{
    public function __construct(private SourceRegistry $sources) {}

    /** @return array{total: int, active: int, new: int, reminders: int, silent: int, queues: array<string, int>, failing: list<string>} */
    public function collect(): array
    {
        $active = fn () => Chat::query()->where('active', true)->whereHas('subscriptions');
        $failures = SourceState::query()->pluck('failures', 'source');

        return [
            'total' => Chat::query()->count(),
            'active' => $active()->count(),
            'new' => Chat::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'reminders' => $active()->whereNotNull('remind_minutes')->count(),
            'silent' => $active()->where('silent', true)->count(),
            'queues' => Subscription::query()->whereIn('chat_id', $active()->select('id'))
                ->selectRaw('queue, count(*) as total')->groupBy('queue')->orderBy('queue')
                ->pluck('total', 'queue')->map(fn ($total) => (int) $total)->all(),
            'failing' => array_values(array_map(
                $this->sources->label(...),
                array_filter($this->sources->keys(), fn (string $key) => ($failures[$key] ?? 0) > 0),
            )),
        ];
    }
}
