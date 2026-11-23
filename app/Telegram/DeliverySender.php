<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\Delivery;
use Illuminate\Support\Facades\Log;

final class DeliverySender
{
    private const MAX_ATTEMPTS = 12;

    /** Keeps below the Telegram limit of about 30 messages per second. */
    private const PAUSE_MICROSECONDS = 50_000;

    public function __construct(private TelegramClient $telegram) {}

    public function flush(int $limit = 200): void
    {
        Delivery::query()->where('status', Delivery::PENDING)->where('expires_at', '<', now())
            ->update(['status' => Delivery::EXPIRED]);

        $deliveries = Delivery::query()->where('status', Delivery::PENDING)
            ->where('available_at', '<=', now())->orderBy('id')->limit($limit)->get();

        foreach ($deliveries as $delivery) {
            if (! $this->send($delivery)) {
                return;
            }
            usleep(self::PAUSE_MICROSECONDS);
        }
    }

    /** @return bool false when the whole run must pause */
    private function send(Delivery $delivery): bool
    {
        $chat = Chat::query()->where('telegram_id', $delivery->telegram_id)->first();

        if ($chat && ! $chat->active && ! str_starts_with($delivery->dedupe_key, 'admin:')) {
            $delivery->update(['status' => Delivery::EXPIRED]);

            return true;
        }

        try {
            $isAdminAlert = str_starts_with($delivery->dedupe_key, 'admin:');
            $this->telegram->send($delivery->telegram_id, $delivery->text, [
                'reply_markup' => Keyboards::bottom($delivery->telegram_id),
                // Service alerts always sound.
                'disable_notification' => $chat !== null && $chat->silent && ! $isAdminAlert,
            ]);
            $chat?->update(['keyboard_version' => Keyboards::version($delivery->telegram_id)]);
            $delivery->update(['status' => Delivery::SENT, 'sent_at' => now(), 'error' => null]);

            return true;
        } catch (TelegramException $e) {
            return $this->failed($delivery, $chat, $e);
        }
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
