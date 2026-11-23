<?php

namespace App\Telegram;

use RuntimeException;

class TelegramException extends RuntimeException
{
    /** @param array<string, mixed> $parameters */
    public function __construct(string $message, int $code = 0, public readonly array $parameters = [])
    {
        parent::__construct($message, $code);
    }

    public function retryAfter(): int
    {
        return (int) ($this->parameters['retry_after'] ?? 0);
    }

    public function isChatGone(): bool
    {
        return $this->getCode() === 403
            || ($this->getCode() === 400 && preg_match('/chat not found|user is deactivated/i', $this->getMessage()));
    }

    public function isTemporary(): bool
    {
        return $this->getCode() === 0 || $this->getCode() === 429 || $this->getCode() >= 500;
    }
}
