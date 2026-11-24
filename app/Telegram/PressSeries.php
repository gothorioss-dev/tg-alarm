<?php

namespace App\Telegram;

/**
 * Joins repeated presses of the same button into one action. Kept in memory: all updates are
 * handled by a single process.
 */
final class PressSeries
{
    /** Telegram deletes up to this many messages in one request. */
    private const MAX_MESSAGES = 100;

    /** @var array<int, array{key: string, until: int}> the last action by chat id, time in milliseconds */
    private array $last = [];

    /** @var array<int, list<int>> messages with repeated presses by chat id */
    private array $messages = [];

    /** Continues the series if this is a repeat. */
    public function isRepeat(int $chatId, string $key, int $seconds): bool
    {
        $last = $this->last[$chatId] ?? null;

        if ($last === null || $last['key'] !== $key || $last['until'] <= $this->now()) {
            return false;
        }

        $this->start($chatId, $key, $seconds);

        return true;
    }

    /** Called after the action is done, so presses made meanwhile are repeats too. */
    public function start(int $chatId, string $key, int $seconds): void
    {
        $this->last[$chatId] = ['key' => $key, 'until' => $this->now() + $seconds * 1000];
    }

    public function forget(int $chatId): void
    {
        unset($this->last[$chatId], $this->messages[$chatId]);
    }

    public function collect(int $chatId, int $messageId): void
    {
        $this->messages[$chatId][] = $messageId;
    }

    public function hasMessages(): bool
    {
        return $this->messages !== [];
    }

    /** @return array<int, list<int>> messages to delete by chat id: their series are over or too long */
    public function takeMessages(bool $all = false): array
    {
        $now = $this->now();
        $this->last = array_filter($this->last, fn (array $last) => $last['until'] > $now);
        $taken = array_filter(
            $this->messages,
            fn (array $ids, int $chatId) => $all || ! isset($this->last[$chatId]) || count($ids) >= self::MAX_MESSAGES,
            ARRAY_FILTER_USE_BOTH,
        );
        $this->messages = array_diff_key($this->messages, $taken);

        return $taken;
    }

    private function now(): int
    {
        return now()->getTimestampMs();
    }
}
