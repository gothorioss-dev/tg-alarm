<?php

namespace App\Address;

use App\Sources\SourceException;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Reads posts of a public channel with replies and names of files through its web preview. */
final class ChannelReader
{
    /**
     * @return array<int, array{message_id: int, reply_to: ?int, text: string, file: ?string, published_at: CarbonImmutable}> posts by id, oldest first
     *
     * @throws SourceException
     */
    public function page(string $channel, ?int $before = null): array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(10)->withUserAgent('Mozilla/5.0')
                ->get("https://t.me/s/{$channel}", array_filter(['before' => $before]));
        } catch (ConnectionException $e) {
            throw new SourceException($e->getMessage(), previous: $e);
        }

        if ($response->status() !== 200) {
            throw new SourceException("HTTP {$response->status()}");
        }

        $posts = [];

        foreach (HTMLDocument::createFromString($response->body(), LIBXML_NOERROR, 'UTF-8')->querySelectorAll('.tgme_widget_message[data-post]') as $message) {
            $id = (int) substr((string) strrchr((string) $message->getAttribute('data-post'), '/'), 1);
            $time = $message->querySelector('.tgme_widget_message_date time[datetime]')?->getAttribute('datetime') ?? $message->querySelector('time[datetime]')?->getAttribute('datetime');

            if ($id === 0 || $time === null) {
                continue;
            }

            $reply = $message->querySelector('.tgme_widget_message_reply')?->getAttribute('href');
            $text = $message->querySelector('.js-message_text');

            foreach ($text?->querySelectorAll('br') ?? [] as $break) {
                $break->replaceWith("\n");
            }

            $posts[$id] = [
                'message_id' => $id,
                'reply_to' => $reply !== null && preg_match('~/(\d+)(?:\?|$)~', $reply, $match) ? (int) $match[1] : null,
                'text' => trim((string) $text?->textContent),
                'file' => trim((string) $message->querySelector('.tgme_widget_message_document_title')?->textContent) ?: null,
                'published_at' => CarbonImmutable::parse($time)->utc(),
            ];
        }

        ksort($posts);

        return $posts;
    }
}
