<?php

namespace App\Telegram;

/** Counts user actions in memory: all updates are handled by a single process. */
final class RateLimiter
{
    /** @var array<int, array{start: int, count: int}> by chat id, the oldest window first */
    private array $windows = [];

    /** @return int number of actions in the current window including this one */
    public function hit(int $chatId): int
    {
        $now = now()->getTimestamp();
        $this->removeExpired($now);

        $this->windows[$chatId] ??= ['start' => $now, 'count' => 0];

        return ++$this->windows[$chatId]['count'];
    }

    private function removeExpired(int $now): void
    {
        $seconds = (int) config('telegram.rate_limit.seconds');

        foreach ($this->windows as $chatId => $window) {
            if ($now - $window['start'] < $seconds) {
                break;
            }
            unset($this->windows[$chatId]);
        }
    }
}
