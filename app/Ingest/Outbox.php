<?php

namespace App\Ingest;

use App\Models\Chat;
use App\Models\Delivery;
use App\Schedule\ChangeImpact;
use App\Schedule\DaySchedule;
use App\Schedule\Interval;
use App\Schedule\Outage;
use App\Telegram\Messages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/** Queues notifications; sending is done by App\Telegram\DeliverySender. */
final class Outbox
{
    public function __construct(private Messages $messages) {}

    public function schedule(string $date, ?DaySchedule $old, DaySchedule $new, bool $correction, int $publicationId, string $source, string $url, CarbonImmutable $publishedAt): void
    {
        $changed = $old ? $new->changedQueues($old) : null;
        $now = CarbonImmutable::now(config('telegram.timezone'));
        $minute = $now->format('Y-m-d') === $date ? $now->hour * 60 + $now->minute : null;
        $impacts = [];

        foreach ($changed ?? [] as $queue) {
            $impacts[$queue] = new ChangeImpact($old->intervals($queue), $new->intervals($queue), $minute);
            $this->forgetReminders($date, $queue, $old, $new, $publicationId);
        }

        $expires = CarbonImmutable::parse($date, config('telegram.timezone'))->addDay()->utc();

        $this->eachChat(function (Chat $chat, array $queues) use ($date, $old, $new, $correction, $publicationId, $source, $url, $publishedAt, $changed, $impacts, $expires) {
            $queues = $changed === null ? $queues : array_values(array_intersect($queues, $changed));

            if ($queues === []) {
                return;
            }

            $text = $old === null
                ? $this->messages->newSchedule($date, $new, $queues, $source, $url)
                : $this->messages->changedSchedule($date, $new, array_intersect_key($impacts, array_flip($queues)), $correction, $source, $url, $publishedAt);

            $this->push($chat->telegram_id, "schedule:{$date}:{$publicationId}:{$chat->id}", $text, $expires);
        });
    }

    public function emergency(int $publicationId, string $text, string $source, string $url): void
    {
        $message = $this->messages->emergency($text, $source, $url);

        $this->eachChat(fn (Chat $chat) => $this->push(
            $chat->telegram_id,
            "emergency:{$publicationId}:{$chat->id}",
            $message,
            CarbonImmutable::now()->addHours(3),
        ));
    }

    public function notice(int $publicationId, string $text, string $source, string $url): void
    {
        $message = $this->messages->notice($text, $source, $url);

        $this->eachChat(fn (Chat $chat) => $this->push(
            $chat->telegram_id,
            "notice:{$publicationId}:{$chat->id}",
            $message,
            CarbonImmutable::now()->addHours(3),
        ));
    }

    public function admin(string $key, string $text): void
    {
        Log::warning($text);

        if ($adminId = (int) config('telegram.admin_id')) {
            $this->push($adminId, "admin:{$key}", '🛠 '.e(mb_strimwidth($text, 0, 3000, '…')));
        }
    }

    public function reminder(Chat $chat, string $queue, Outage $outage, CarbonImmutable $now): void
    {
        $this->push(
            $chat->telegram_id,
            "remind:{$chat->id}:{$queue}:{$outage->start->timestamp}",
            $this->messages->reminder($queue, $outage, $now),
            $outage->start->utc(),
        );
    }

    /** A called off outage that returns later is a new event and needs a new reminder. */
    private function forgetReminders(string $date, string $queue, DaySchedule $old, DaySchedule $new, int $publicationId): void
    {
        $starts = array_map(fn (Interval $interval) => $interval->from, $new->intervals($queue));

        foreach ($old->intervals($queue) as $interval) {
            if (in_array($interval->from, $starts, true)) {
                continue;
            }

            $start = CarbonImmutable::parse($date, config('telegram.timezone'))
                ->setTime(intdiv($interval->from, 60), $interval->from % 60)->timestamp;

            foreach (Delivery::query()->where('dedupe_key', 'like', "remind:%:{$queue}:{$start}")->get() as $delivery) {
                $delivery->update([
                    'dedupe_key' => "{$delivery->dedupe_key}:void:{$publicationId}",
                    'status' => $delivery->status === Delivery::PENDING ? Delivery::EXPIRED : $delivery->status,
                ]);
            }
        }
    }

    private function eachChat(callable $callback): void
    {
        Chat::query()->where('active', true)->whereHas('subscriptions')->with('subscriptions')
            ->each(fn (Chat $chat) => $callback($chat, $chat->subscriptions->pluck('queue')->all()) ?? true);
    }

    private function push(int $telegramId, string $key, string $text, ?CarbonImmutable $expiresAt = null): void
    {
        Delivery::query()->firstOrCreate(['dedupe_key' => $key], [
            'telegram_id' => $telegramId,
            'text' => $text,
            'status' => Delivery::PENDING,
            'available_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }
}
