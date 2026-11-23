<?php

namespace App\Sources;

use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Reads a public channel through its web preview. */
final class TelegramChannelSource implements Source
{
    private const MAX_PAGES = 15;

    public function __construct(private string $channel) {}

    public function fetch(array $state): FetchResult
    {
        $lastId = (int) ($state['last_id'] ?? 0);
        $posts = $this->page();

        if ($posts === []) {
            throw new SourceException('Channel page format is not recognized');
        }

        $newest = max(array_keys($posts));

        // Fill the gap when more posts appeared than one page holds.
        for ($i = 0; $lastId > 0 && min(array_keys($posts)) > $lastId + 1 && $i < self::MAX_PAGES; $i++) {
            $older = $this->page(min(array_keys($posts)));

            if ($older === []) {
                break;
            }
            $posts += $older;
        }

        ksort($posts);

        return new FetchResult(array_values(array_filter($posts)), ['last_id' => max($newest, $lastId)]);
    }

    /** @return array<int, Publication|null> posts by id, null for posts without text */
    private function page(?int $before = null): array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(10)->withUserAgent('Mozilla/5.0')
                ->get("https://t.me/s/{$this->channel}", array_filter(['before' => $before]));
        } catch (ConnectionException $e) {
            throw new SourceException($e->getMessage(), previous: $e);
        }

        if ($response->status() !== 200) {
            throw new SourceException("HTTP {$response->status()}");
        }

        $document = HTMLDocument::createFromString($response->body(), LIBXML_NOERROR, 'UTF-8');
        $posts = [];

        foreach ($document->querySelectorAll('.tgme_widget_message[data-post]') as $message) {
            $path = $message->getAttribute('data-post');
            $id = (int) substr(strrchr($path, '/') ?: '', 1);
            $time = $message->querySelector('a.tgme_widget_message_date time')?->getAttribute('datetime');
            $text = $message->querySelector('.tgme_widget_message_text.js-message_text');

            if ($id === 0) {
                continue;
            }
            if ($time === null || $text === null) {
                $posts[$id] = null;

                continue;
            }

            $plain = HtmlText::fromElement($text);
            $posts[$id] = new Publication(
                (string) $id,
                (string) strtok($plain, "\n"),
                $plain,
                "https://t.me/{$path}",
                CarbonImmutable::parse($time)->utc(),
            );
        }

        return $posts;
    }
}
