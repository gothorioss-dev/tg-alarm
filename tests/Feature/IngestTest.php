<?php

namespace Tests\Feature;

use App\Ingest\Ingestor;
use App\Ingest\Poller;
use App\Models\Chat;
use App\Models\Delivery;
use App\Models\PublicationRecord;
use App\Models\Schedule;
use App\Models\SourceState;
use App\Sources\FetchResult;
use App\Sources\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 11:00', 'Europe/Kyiv'));

        foreach (['site', 'telegram'] as $source) {
            SourceState::query()->create(['source' => $source, 'initialized' => true]);
        }
        $this->chat(1, ['5.2']);
        $this->chat(2, ['1.1']);
    }

    private function chat(int $id, array $queues): void
    {
        $chat = Chat::query()->create(['telegram_id' => $id, 'type' => 'private']);
        foreach ($queues as $queue) {
            $chat->subscriptions()->create(['queue' => $queue]);
        }
    }

    private function version(string $source, string $at, string $rows, ?string $declared = null, ?string $id = null, string $date = '23 Листопада'): void
    {
        $title = "ОНОВЛЕНО ГПВ НА {$date}".($declared ? " (оновлено {$declared})" : '');
        $this->publish($source, $at, "{$title}\n{$rows}", $id);
    }

    private function publish(string $source, string $at, string $text, ?string $id = null): void
    {
        $publication = new Publication(
            $id ?? (string) ++$this->sequence, strtok($text, "\n"), $text, 'https://example.com/p',
            CarbonImmutable::parse("2026-11-23 {$at}", 'Europe/Kyiv')->utc(),
        );
        app(Ingestor::class)->ingest([$source => new FetchResult([$publication], [])]);
    }

    private function sent(int $chat): array
    {
        return Delivery::query()->where('telegram_id', $chat)->orderBy('id')->pluck('text')->all();
    }

    private function rows(string $q52): string
    {
        return "1.1: 07:00 – 12:00\n5.2: {$q52}";
    }

    public function test_only_affected_queue_is_notified(): void
    {
        $this->version('telegram', '09:00', $this->rows('10:30 - 13:30'));
        $this->version('telegram', '10:48', $this->rows('10:30 - 13:30, 17:00 - 22:00'));

        $this->assertCount(2, $this->sent(1));
        $this->assertCount(1, $this->sent(2));
        $this->assertStringContainsString('Додано відключення: 17:00–22:00', $this->sent(1)[1]);
    }

    public function test_queue_missing_from_new_version_means_no_outages(): void
    {
        $this->version('site', '09:00', $this->rows('10:30 – 13:30'), '08:57');
        $this->version('site', '09:30', '1.1: 07:00 – 12:00', '09:28');

        $this->assertStringContainsString('без відключень', $this->sent(1)[1]);
        $this->assertStringContainsString('Скасовано відключення: 10:30–13:30', $this->sent(1)[1]);
    }

    public function test_same_version_from_both_sources_is_sent_once(): void
    {
        $this->version('telegram', '10:48', $this->rows('17:00 - 22:00'));
        $this->version('site', '10:51', $this->rows('17:00 – 22:00'), '10:47');

        $this->assertCount(1, $this->sent(1));
        $this->assertSame('site', Schedule::query()->first()->source);
    }

    public function test_late_copy_of_old_version_does_not_roll_back(): void
    {
        $this->version('telegram', '10:34', $this->rows('10:30 - 13:30'));
        $this->version('telegram', '10:36', $this->rows('17:00 - 22:00'));
        $this->version('site', '10:37', $this->rows('10:30 – 13:30'), '10:32');
        $this->version('site', '10:39', $this->rows('17:00 – 22:00'), '10:35');

        $this->assertCount(2, $this->sent(1));
    }

    public function test_genuine_return_to_previous_version_is_applied(): void
    {
        foreach (['09:00' => '10:30 - 13:30', '09:40' => '17:00 - 22:00', '10:20' => '10:30 - 13:30'] as $at => $rows) {
            $this->version('telegram', $at, $this->rows($rows));
            $this->version('site', CarbonImmutable::parse($at)->addMinutes(3)->format('H:i'), $this->rows($rows), $at);
        }

        $this->assertCount(3, $this->sent(1));
    }

    public function test_site_wins_when_the_same_version_differs(): void
    {
        $this->version('telegram', '10:50', $this->rows('17:00 - 22:00, 23:00 - 23:30'), '10:47');
        $this->version('site', '10:51', $this->rows('17:00 – 22:00'), '10:47');

        $this->assertStringContainsString('Уточнення', $this->sent(1)[1]);
        $this->assertSame('site', Schedule::query()->first()->source);
    }

    public function test_channel_does_not_override_site_for_the_same_version(): void
    {
        $this->version('site', '10:49', $this->rows('17:00 – 22:00'), '10:47');
        $this->version('telegram', '10:51', $this->rows('17:00 - 22:00, 23:00 - 23:30'), '10:47');

        $this->assertCount(1, $this->sent(1));
    }

    public function test_priority_is_configurable(): void
    {
        config(['sources.sources.telegram.priority' => 200]);
        $this->version('site', '10:49', $this->rows('17:00 – 22:00'), '10:47');
        $this->version('telegram', '10:51', $this->rows('17:00 - 22:00, 23:00 - 23:30'), '10:47');

        $this->assertStringContainsString('Уточнення', $this->sent(1)[1]);
    }

    public function test_newer_channel_version_overrides_site(): void
    {
        $this->version('site', '10:20', $this->rows('17:00 – 22:00'), '10:17');
        $this->version('telegram', '10:50', $this->rows('18:00 - 22:00'));

        $this->assertCount(2, $this->sent(1));
    }

    public function test_edit_of_the_same_post_is_detected_and_repeat_is_not(): void
    {
        $this->version('site', '10:49', $this->rows('17:00 – 22:00'), '10:47', 'a');
        $this->version('site', '10:49', $this->rows('17:00 – 22:00'), '10:47', 'a');
        $this->version('site', '10:49', $this->rows('18:00 – 22:00'), '10:47', 'a');

        $this->assertCount(2, $this->sent(1));
    }

    public function test_dates_are_independent_and_past_is_silent(): void
    {
        $this->version('site', '10:00', $this->rows('17:00 – 22:00'));
        $this->version('site', '10:01', $this->rows('18:00 – 22:00'), date: '24 Листопада');
        $this->version('site', '10:02', $this->rows('18:00 – 22:00'), date: '22 Листопада');

        $this->assertCount(2, $this->sent(1));
        $this->assertSame(3, Schedule::query()->count());
    }

    public function test_broken_schedule_is_not_applied_and_admin_is_alerted(): void
    {
        $this->version('site', '10:00', $this->rows('17:00 – 22:00'));
        $this->version('site', '10:30', "1.1: 07:00 – 12:00\n5.2: скасовано");

        $this->assertCount(1, $this->sent(1));
        $this->assertCount(1, $this->sent(999));
    }

    public function test_emergency_goes_to_everyone_once_and_only_from_the_site(): void
    {
        $text = "УВАГА! ДІЮТЬ СПЕЦІАЛЬНІ ГРАФІКИ АВАРІЙНИХ ВІДКЛЮЧЕНЬ\nТекст";
        $this->publish('site', '10:50', $text, 'e');
        $this->publish('site', '10:50', $text.' змінено', 'e');
        $this->publish('telegram', '10:51', $text);

        $this->assertCount(1, $this->sent(1));
        $this->assertCount(1, $this->sent(2));
    }

    private function atTime(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-11-23 {$time}", 'Europe/Kyiv'));
    }

    public function test_change_of_past_time_only_is_silent_but_stored(): void
    {
        $this->atTime('15:00');
        $this->version('site', '09:00', $this->rows('10:30 – 13:30'), '08:57');
        $this->version('site', '14:50', $this->rows('10:30 – 12:30'), '14:47');

        $this->assertCount(1, $this->sent(1));
        $this->assertSame(['10:30–12:30'], array_map('strval', Schedule::query()->first()->day()->intervals('5.2')));

        config(['sources.notify_past_changes' => true]);
        $this->version('site', '14:55', $this->rows('10:30 – 13:00'), '14:52');
        $this->assertStringContainsString('уже минуло', $this->sent(1)[1]);
    }

    public function test_outage_added_after_its_start_is_reported_first(): void
    {
        $this->atTime('10:49');
        $this->version('site', '09:00', $this->rows('17:00 – 22:00'), '08:57');
        $this->version('site', '10:48', $this->rows('10:30 – 13:30, 17:00 – 22:00'), '10:47');

        $this->assertStringContainsString('Станом на 10:49 відключення вже діє — до 13:30', $this->sent(1)[1]);
    }

    public function test_cancelled_or_changed_current_outage_is_reported(): void
    {
        $this->atTime('11:00');
        $this->version('site', '09:00', $this->rows('10:30 – 13:30'), '08:57');
        $this->version('site', '10:30', $this->rows('10:30 – 14:30'), '10:29');
        $this->version('site', '10:58', $this->rows('17:00 – 22:00'), '10:57');

        $this->assertStringContainsString('діє відключення — до 14:30</b> (було до 13:30)', $this->sent(1)[1]);
        $this->assertStringContainsString('відключення скасовано</b> (мало бути до 14:30)', $this->sent(1)[2]);
    }

    public function test_mixed_change_marks_the_past_part_and_boundary_is_past(): void
    {
        $this->atTime('13:30');
        $this->version('site', '09:00', $this->rows('10:30 – 13:30'), '08:57');
        $this->version('site', '13:20', $this->rows('10:30 – 12:00, 18:00 – 20:00'), '13:19');

        $text = $this->sent(1)[1];
        $this->assertStringContainsString('12:00–13:30 (уже минуло)', $text);
        $this->assertStringNotContainsString('Станом на', $text);
    }

    public function test_schedule_first_published_during_an_outage_shows_the_current_state(): void
    {
        $this->atTime('11:00');
        $this->version('site', '10:55', $this->rows('10:30 – 13:30'));
        $this->assertStringContainsString('вже діє — до 13:30', $this->sent(1)[0]);
    }

    public function test_tomorrow_changes_are_never_past(): void
    {
        $this->atTime('23:00');
        $this->version('site', '20:00', $this->rows('01:00 – 02:00'), '19:57', null, '24 Листопада');
        $this->version('site', '22:50', $this->rows('03:00 – 04:00'), '22:47', null, '24 Листопада');
        $this->assertCount(2, $this->sent(1));
        $this->assertStringNotContainsString('Станом на', $this->sent(1)[1]);
    }

    public function test_first_run_on_real_data_is_silent_and_then_nothing_repeats(): void
    {
        SourceState::query()->delete();
        Http::fake([
            'www.zoe.com.ua/*' => Http::response(file_get_contents(base_path('tests/Fixtures/rss.xml'))),
            't.me/*' => Http::response(file_get_contents(base_path('tests/Fixtures/telegram.html'))),
        ]);
        app(Poller::class)->pollDue();
        $count = PublicationRecord::query()->count();
        $this->travel(2)->minutes();
        app(Poller::class)->pollDue();

        $this->assertSame(0, Delivery::query()->count());
        $this->assertSame($count, PublicationRecord::query()->count());
        $this->assertSame(['10:30–13:30', '17:00–22:00'], array_map('strval', Schedule::query()->find('2026-11-23')->day()->intervals('5.2')));
    }

    public function test_source_failure_alerts_admin_once_and_reports_recovery(): void
    {
        $fail = true;
        Http::fake(function () use (&$fail) {
            return $fail ? Http::response('', 500) : Http::response(file_get_contents(base_path('tests/Fixtures/rss.xml')));
        });
        config(['sources.sources.telegram.enabled' => false]);

        for ($i = 0; $i < 13; $i++) {
            app(Poller::class)->pollDue();
            $this->travel(61)->seconds();
        }
        $this->assertCount(1, $this->sent(999));
        $fail = false;
        app(Poller::class)->pollDue();
        $this->assertStringContainsString('знову працює', $this->sent(999)[1]);
    }
}
