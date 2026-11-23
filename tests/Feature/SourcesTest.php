<?php

namespace Tests\Feature;

use App\Schedule\Kind;
use App\Schedule\ScheduleParser;
use App\Sources\RssSource;
use App\Sources\SourceException;
use App\Sources\TelegramChannelSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourcesTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/{$name}"));
    }

    public function test_real_rss_and_channel_give_the_same_schedule(): void
    {
        Http::fake([
            'www.zoe.com.ua/*' => Http::response($this->fixture('rss.xml'), 200, ['ETag' => '"x"']),
            't.me/*' => Http::response($this->fixture('telegram.html')),
        ]);
        $parser = new ScheduleParser;
        $rss = (new RssSource('https://www.zoe.com.ua/feed/'))->fetch([]);
        $tg = (new TelegramChannelSource('zoe_alarm'))->fetch([]);

        $this->assertCount(10, $rss->publications);
        $kinds = array_count_values(array_map(fn ($p) => $parser->classify($p->text)->value, $rss->publications));
        $this->assertSame(['schedule' => 8, 'emergency' => 2], [Kind::Schedule->value => $kinds['schedule'], Kind::Emergency->value => $kinds['emergency']]);

        $site = $rss->publications[0];
        $post = collect($tg->publications)->firstWhere('externalId', '82606');
        $a = $parser->parse($site->text, $site->publishedAt);
        $b = $parser->parse($post->text, $post->publishedAt);

        $this->assertSame('2026-11-23', $a->date);
        $this->assertSame($a->schedule->hash(), $b->schedule->hash());
        $this->assertSame('08:47', $a->declaredAt->format('H:i'));
        $this->assertSame(82612, $tg->state['last_id']);

        foreach ($rss->publications as $p) {
            if ($parser->classify($p->text) === Kind::Schedule) {
                $parser->parse($p->text, $p->publishedAt);
            }
        }
    }

    public function test_rss_uses_conditional_requests(): void
    {
        Http::fake(['*' => Http::response('', 304)]);
        $result = (new RssSource('https://site/feed'))->fetch(['etag' => '"x"']);

        $this->assertSame(['etag' => '"x"'], $result->state);
        Http::assertSent(fn ($r) => $r->hasHeader('If-None-Match', '"x"'));
    }

    public function test_channel_gap_is_filled(): void
    {
        Http::fake([
            '*before=82593' => Http::response($this->fixture('telegram-before.html')),
            '*' => Http::response($this->fixture('telegram.html')),
        ]);
        $result = (new TelegramChannelSource('zoe_alarm'))->fetch(['last_id' => 82580]);

        $this->assertContains('82589', array_column($result->publications, 'externalId'));
        Http::assertSentCount(2);
    }

    public function test_unrecognized_pages_fail(): void
    {
        Http::fake(['*' => Http::response('<html></html>')]);
        $failures = 0;

        foreach ([new RssSource('https://site/feed'), new TelegramChannelSource('x')] as $source) {
            try {
                $source->fetch([]);
            } catch (SourceException) {
                $failures++;
            }
        }

        $this->assertSame(2, $failures);
    }
}
