<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\Delivery;
use App\Monitor\ProcessStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

final class DeliverySender
{
    private const MAX_ATTEMPTS = 12;

    public function __construct(private TelegramClient $telegram, private ProcessStatus $status, private Access $access) {}

    public function flush(DeliveryChannel $channel = DeliveryChannel::Broadcast, int $limit = 200): void
    {
        Delivery::query()->where('status', Delivery::PENDING)->where('expires_at', '<', now())
            ->update(['status' => Delivery::EXPIRED]);

        $queue = $channel->pending()->where('available_at', '<=', now())->limit($limit)->pluck('telegram_id', 'id')->all();

        while ($queue !== []) {
            $started = microtime(true);
            $ids = $this->takeBatch($queue);

            // A long run is not a hang.
            $this->status->beat();

            if (! $this->sendBatch($ids)) {
                return;
            }
            Sleep::usleep((int) max(0, (count($ids) / $channel->rate() - (microtime(true) - $started)) * 1_000_000));
        }
    }

    /**
     * Messages to one chat never go at the same time, which keeps their order.
     *
     * @param  array<int, int>  $queue  chat ids by delivery id; the taken ones are removed
     * @return list<int>
     */
    private function takeBatch(array &$queue): array
    {
        $batch = array_slice(array_unique($queue), 0, max(1, (int) config('telegram.send.parallel')), true);
        $queue = array_diff_key($queue, $batch);

        return array_keys($batch);
    }

    /**
     * @param  list<int>  $ids
     * @return bool false when the whole run must pause
     */
    private function sendBatch(array $ids): bool
    {
        // Read again: a message may have been called off since the run started.
        $deliveries = Delivery::query()->whereIn('id', $ids)->where('status', Delivery::PENDING)->get()->keyBy('id');
        $chats = Chat::query()->whereIn('telegram_id', $deliveries->pluck('telegram_id'))->get()->keyBy('telegram_id');
        $messages = [];

        foreach ($ids as $id) {
            if (! $delivery = $deliveries->get($id)) {
                continue;
            }

            $chat = $chats->get($delivery->telegram_id);
            $isAdminAlert = str_starts_with($delivery->dedupe_key, 'admin:');

            if ($chat && ! $chat->active && ! $isAdminAlert) {
                $delivery->update(['status' => Delivery::EXPIRED]);

                continue;
            }

            $messages[$id] = [$delivery->telegram_id, $delivery->text, [
                'reply_markup' => Keyboards::bottom($delivery->telegram_id, $this->access->perks($chat)),
                // Service alerts always sound.
                'disable_notification' => $chat !== null && $chat->silent && ! $isAdminAlert,
            ]];
        }

        if ($messages === []) {
            return true;
        }

        $errors = $this->telegram->sendMany($messages);

        return DB::transaction(function () use ($errors, $deliveries, $chats) {
            $continue = true;

            foreach ($errors as $id => $error) {
                $delivery = $deliveries->get($id);
                $chat = $chats->get($delivery->telegram_id);

                if ($error !== null) {
                    $continue = $this->failed($delivery, $chat, $error) && $continue;

                    continue;
                }
                $chat?->update(['keyboard_version' => Keyboards::version($delivery->telegram_id, $this->access->perks($chat))]);
                $delivery->update(['status' => Delivery::SENT, 'sent_at' => now(), 'error' => null]);
            }

            return $continue;
        });
    }

    private function failed(Delivery $delivery, ?Chat $chat, TelegramException $e): bool
    {
        Log::warning("Delivery {$delivery->id} failed: {$e->getMessage()}");

        if ($e->isChatGone()) {
            $chat?->update(['active' => false]);
            $delivery->update(['status' => Delivery::FAILED, 'error' => $e->getMessage()]);

            return true;
        }
        // A wrong token is a configuration problem, not a problem of this message.
        if ($e->getCode() === 401 || $e->getCode() === 404) {
            $delivery->update(['available_at' => now()->addMinute(), 'error' => $e->getMessage()]);

            return false;
        }

        $attempts = $delivery->attempts + 1;
        $exhausted = ! $e->isTemporary() || ($e->getCode() !== 429 && $attempts >= self::MAX_ATTEMPTS);
        $delivery->update([
            'attempts' => $attempts,
            'error' => $e->getMessage(),
            'status' => $exhausted ? Delivery::FAILED : Delivery::PENDING,
            'available_at' => now()->addSeconds($e->retryAfter() ?: min(600, 5 * 2 ** min($attempts, 7))),
        ]);

        return ! $e->isTemporary();
    }
}
