<?php

namespace App\Telegram;

use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramClient
{
    /** @var callable|null shared by all requests so that the connection stays open */
    private $handler = null;

    /** @param array<string, mixed> $params */
    public function call(string $method, array $params = [], int $timeout = 20): mixed
    {
        try {
            $response = Http::setHandler($this->handler())
                ->timeout($timeout)->connectTimeout(10)->asJson()
                ->post($this->url($method), $params);
        } catch (ConnectionException $e) {
            throw $this->connectionError($e);
        }

        return $this->result($response);
    }

    /** @param array<string, mixed> $extra */
    public function send(int $chatId, string $html, array $extra = []): mixed
    {
        return $this->call('sendMessage', $this->message($chatId, $html, $extra));
    }

    /**
     * Sends messages at the same time.
     *
     * @param  array<int, array{int, string, array<string, mixed>}>  $messages  chat id, text and extra parameters
     * @return array<int, TelegramException|null> error of each message
     */
    public function sendMany(array $messages): array
    {
        $url = $this->url('sendMessage');
        $responses = Http::pool(function (Pool $pool) use ($messages, $url) {
            foreach ($messages as $key => $message) {
                $pool->as((string) $key)->setHandler($this->handler())
                    ->timeout(20)->connectTimeout(10)->asJson()->post($url, $this->message(...$message));
            }
        });
        $errors = [];

        foreach (array_keys($messages) as $key) {
            $response = $responses[$key] ?? null;

            try {
                if (! $response instanceof Response) {
                    throw $this->connectionError($response);
                }
                $this->result($response);
                $errors[$key] = null;
            } catch (TelegramException $e) {
                $errors[$key] = $e;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function message(int $chatId, string $html, array $extra = []): array
    {
        return [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            ...$extra,
        ];
    }

    private function handler(): callable
    {
        return $this->handler ??= Utils::chooseHandler();
    }

    private function url(string $method): string
    {
        $token = (string) config('telegram.token');

        if ($token === '') {
            throw new TelegramException('TELEGRAM_BOT_TOKEN is not set', 401);
        }

        return "https://api.telegram.org/bot{$token}/{$method}";
    }

    /** The original exception contains the URL with the token, so it is not chained. */
    private function connectionError(?Throwable $e): TelegramException
    {
        return new TelegramException(str_replace((string) config('telegram.token'), '***', $e?->getMessage() ?? 'No response'));
    }

    private function result(Response $response): mixed
    {
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
}
