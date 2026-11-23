<?php

namespace App\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class RssSource implements Source
{
    public function __construct(private string $url) {}

    public function fetch(array $state): FetchResult
    {
        try {
            $response = Http::timeout(20)->connectTimeout(10)->withUserAgent('Mozilla/5.0')->withHeaders(array_filter([
                'If-Modified-Since' => $state['last_modified'] ?? null,
                'If-None-Match' => $state['etag'] ?? null,
            ]))->get($this->url);
        } catch (ConnectionException $e) {
            throw new SourceException($e->getMessage(), previous: $e);
        }

        if ($response->status() === 304) {
            return new FetchResult([], $state);
        }
        if ($response->status() !== 200) {
            throw new SourceException("HTTP {$response->status()}");
        }

        return new FetchResult($this->parse($response->body()), array_filter([
            'last_modified' => $response->header('Last-Modified'),
            'etag' => $response->header('ETag'),
        ]));
    }

    /** @return list<Publication> */
    private function parse(string $xml): array
    {
        $feed = @simplexml_load_string($xml, options: LIBXML_NONET);

        if ($feed === false || ! isset($feed->channel->item)) {
            throw new SourceException('Feed format is not recognized');
        }

        $publications = [];

        foreach ($feed->channel->item as $item) {
            $title = trim((string) $item->title);
            $published = strtotime((string) $item->pubDate);

            if ($published === false || (string) $item->guid === '') {
                throw new SourceException('Feed item has no date or guid');
            }

            $body = HtmlText::fromHtml((string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded);
            // WordPress appends a "post first appeared on" line.
            $body = trim(preg_replace('/^Запис .*з.явиться на.*$/um', '', $body));

            $publications[] = new Publication(
                (string) $item->guid,
                $title,
                $title."\n".$body,
                (string) $item->link,
                CarbonImmutable::createFromTimestamp($published, 'UTC'),
            );
        }

        return $publications;
    }
}
