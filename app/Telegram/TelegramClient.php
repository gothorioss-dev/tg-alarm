<?php

namespace App\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    /** @param array<string, mixed> $params */
    public function call(string $method, array $params = [], int $timeout = 20): mixed
    {
        $token = (string) config('telegram.token');

        if ($token === '') {
            throw new TelegramException('TELEGRAM_BOT_TOKEN is not set', 401);
        }

        try {
            $response = Http::timeout($timeout)->connectTimeout(10)->asJson()
                ->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (ConnectionException $e) {
            // The original exception contains the URL with the token, so it is not chained.
            throw new TelegramException(str_replace($token, '***', $e->getMessage()));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new TelegramException('Invalid API response', $response->status() >= 400 ? $response->status() : 0);
        }
        if (! ($body['ok'] ?? false)) {
            throw new TelegramException(
                (string) ($body['description'] ?? 'Unknown error'),
                (int) ($body['error_code'] ?? $response->status()),
                $body['parameters'] ?? [],
            );
        }

        return $body['result'];
    }

    /** @param array<string, mixed> $extra */
    public function send(int $chatId, string $html, array $extra = []): mixed
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            ...$extra,
        ]);
    }
}
