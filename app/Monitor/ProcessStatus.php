<?php

namespace App\Monitor;

/** State of the current process reported to the monitor. */
final class ProcessStatus
{
    public const WINDOW = 300;

    public const STARTS_WINDOW = 600;

    private const WRITE_INTERVAL = 5;

    private const LAG_WINDOW = 60;

    private ?string $name = null;

    private int $startedAt = 0;

    private int $writtenAt = 0;

    /** @var list<int> */
    private array $starts = [];

    /** @var list<array{int, bool}> time and whether the database has failed */
    private array $errors = [];

    /** @var array<int, int> time by chat id */
    private array $flood = [];

    /** @var list<array{int, int, int}> time, seconds of waiting, updates in the batch */
    private array $lags = [];

    public function __construct(private StatusFiles $files) {}

    public function start(string $name): void
    {
        $this->name = $name;
        $this->startedAt = $this->now();
        $this->starts = [...($this->files->read($name)['starts'] ?? []), $this->startedAt];
        $this->write();
    }

    /** A sign of life; may be called as often as needed. */
    public function beat(): void
    {
        if ($this->name !== null && $this->now() - $this->writtenAt >= self::WRITE_INTERVAL) {
            $this->write();
        }
    }

    public function error(string $message): void
    {
        if ($this->name !== null) {
            $this->errors[] = [$this->now(), preg_match('/SQLSTATE|database (is locked|disk)|readonly database/i', $message) === 1];
            // Only the number matters, so it is limited.
            $this->errors = array_slice($this->errors, -1000);
        }
    }

    public function flood(int $chatId): void
    {
        if ($this->name !== null) {
            $this->flood[$chatId] = $this->now();
        }
    }

    public function lag(int $seconds, int $backlog): void
    {
        // Messages sent while the process was not running are late by nature.
        if ($this->name !== null && $this->now() - $this->startedAt >= self::LAG_WINDOW) {
            $this->lags[] = [$this->now(), $seconds, $backlog];
        }
    }

    private function write(): void
    {
        $now = $this->now();
        $this->starts = array_values(array_filter($this->starts, fn (int $time) => $now - $time < self::STARTS_WINDOW));
        $this->errors = array_values(array_filter($this->errors, fn (array $error) => $now - $error[0] < self::WINDOW));
        $this->flood = array_filter($this->flood, fn (int $time) => $now - $time < self::WINDOW);
        $this->lags = array_values(array_filter($this->lags, fn (array $lag) => $now - $lag[0] < self::LAG_WINDOW));
        $this->writtenAt = $now;

        $this->files->write($this->name, [
            'beat' => $now,
            'starts' => $this->starts,
            'errors' => count($this->errors),
            'database_errors' => count(array_filter(array_column($this->errors, 1))),
            'flood' => count($this->flood),
            'lag' => max([0, ...array_column($this->lags, 1)]),
            'backlog' => max([0, ...array_column($this->lags, 2)]),
        ]);
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }
}
