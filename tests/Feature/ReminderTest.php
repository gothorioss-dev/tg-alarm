<?php

namespace Tests\Feature;

use App\Ingest\Reminders;
use App\Models\Chat;
use App\Models\Delivery;
use App\Models\PublicationRecord;
use App\Models\Schedule;
use App\Models\SourceState;
use App\Schedule\DaySchedule;
use App\Schedule\Interval;
use App\Telegram\UpdateHandler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private', 'active' => true, 'remind_minutes' => 30]);
        $this->chat->subscriptions()->create(['queue' => '5.2']);
        $this->schedule('2026-11-23', [[1020, 1320], [1410, 1440]]);
    }

    private function schedule(string $date, array $intervals): void
    {
        $record = PublicationRecord::query()->create([
            'source' => 'site', 'external_id' => uniqid(), 'content_hash' => 'x', 'kind' => 'schedule', 'status' => 'applied',
            'title' => 't', 'url' => 'u', 'published_at' => now(), 'fetched_at' => now(),
        ]);
        $day = new DaySchedule(['5.2' => array_map(fn ($i) => new Interval(...$i), $intervals)]);
        Schedule::query()->updateOrCreate(['date' => $date], ['publication_id' => $record->id, 'source' => 'site', 'queues' => $day->toArray()]);
    }

    private function at(string $time, string $date = '2026-11-23'): array
    {
        $this->travelTo(CarbonImmutable::parse("{$date} {$time}", 'Europe/Kyiv'));
        app(Reminders::class)->queueDue();

        return Delivery::query()->orderBy('id')->pluck('text')->all();
    }

    private function press(string $data): string
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 60]]));
        app(UpdateHandler::class)->handle(['callback_query' => ['id' => 'x', 'data' => $data, 'from' => ['id' => 7], 'message' => ['message_id' => 1, 'chat' => ['id' => 5, 'type' => 'private']]]]);

        return (string) Http::recorded()->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), 'editMessageText'))->last()?->data()['text'];
    }

    public function test_reminder_is_queued_once_in_time(): void
    {
        $this->assertCount(0, $this->at('16:29'));
        $texts = $this->at('16:30');
        $this->assertStringContainsString('через 30 хв', $texts[0]);
        $this->assertStringContainsString('з 17:00 до 22:00', $texts[0]);
        $this->assertCount(1, $this->at('16:45'));
        $this->assertCount(1, $this->at('17:00'));
    }

    public function test_moved_outage_is_reminded_again(): void
    {
        $this->at('16:35');
        $this->schedule('2026-11-23', [[1080, 1320]]);
        $this->assertCount(1, $this->at('17:00'));
        $this->assertCount(2, $this->at('17:30'));
    }

    public function test_outage_over_midnight_is_one_outage(): void
    {
        $this->schedule('2026-11-24', [[0, 120]]);
        $texts = $this->at('23:05');
        $this->assertStringContainsString('до 02:00 (24 листопада)', $texts[0]);
        $this->assertCount(1, $this->at('23:45'));
        $this->assertCount(1, $this->at('00:00', '2026-11-24'));
    }

    public function test_no_reminders_when_off_or_inactive(): void
    {
        $this->chat->update(['active' => false]);
        $this->assertCount(0, $this->at('16:45'));
        $this->chat->update(['active' => true, 'remind_minutes' => null]);
        $this->assertCount(0, $this->at('16:45'));
    }

    public function test_reminder_is_configured_with_buttons(): void
    {
        $this->press('r:60');
        $this->assertSame(60, $this->chat->refresh()->remind_minutes);
        $this->press('r:7');
        $this->assertSame(60, $this->chat->refresh()->remind_minutes);
        $this->press('r:0');
        $this->assertNull($this->chat->refresh()->remind_minutes);
        $this->assertStringContainsString('Налаштування', $this->press('settings'));
    }

    public function test_schedule_after_selection_comes_from_stored_data(): void
    {
        SourceState::query()->create(['source' => 'site', 'succeeded_at' => CarbonImmutable::parse('2026-11-23 13:05', 'Europe/Kyiv')->utc()]);
        $this->at('13:48');
        $text = $this->press('done');
        $this->assertStringContainsString('Наступне за графіком', $text);
        $this->assertStringContainsString('з 17:00 до 22:00 (через 3 год 12 хв)', $text);
        $this->assertStringContainsString('13:05', $text);
        Http::assertNotSent(fn ($r) => ! str_contains($r->url(), 'api.telegram.org'));

        // The bot knows the schedule, not whether the power is really on.
        $this->at('18:00');
        $text = $this->press('schedule');
        $this->assertStringNotContainsString('зараз', mb_strtolower($text));
        $this->assertStringNotContainsString('Це лише графік', $text);
        $this->assertStringContainsString('Це лише графік', $this->press('help'));
    }

    public function test_first_start_shows_guide_and_every_start_shows_selection(): void
    {
        $texts = [];
        Http::fake(function ($r) use (&$texts) {
            $texts[] = $r['text'] ?? '';

            return Http::response(['ok' => true, 'result' => ['message_id' => 60]]);
        });
        $start = fn (int $id) => app(UpdateHandler::class)->handle(['message' => ['message_id' => 50, 'text' => '/start', 'chat' => ['id' => $id, 'type' => 'private'], 'from' => ['id' => $id]]]);
        $start(77);
        $this->assertStringContainsString('Оберіть чергу', $texts[1]);
        $start(5);
        $this->assertStringContainsString('Довідник', $texts[0]);
        $this->assertStringContainsString('збережено', $texts[2]);
        $this->assertStringContainsString('Оберіть чергу', $texts[3]);
    }
}
