<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Monitor\Alerts;
use App\Monitor\Checks;
use App\Monitor\Problem;
use App\Monitor\ProcessStatus;
use App\Monitor\StatusFiles;
use App\Monitor\SystemInfo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var array{disk: ?int, memory: ?int, load: ?float} */
    private array $resources = ['disk' => 5000, 'memory' => 400, 'load' => 0.1];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/zoe-monitor-'.uniqid();
        config(['monitor.path' => $this->dir]);
        $this->at('12:00');
        $system = $this->createStub(SystemInfo::class);
        $system->method('freeDisk')->willReturnCallback(fn () => $this->resources['disk']);
        $system->method('availableMemory')->willReturnCallback(fn () => $this->resources['memory']);
        $system->method('load')->willReturnCallback(fn () => $this->resources['load']);
        $this->app->instance(SystemInfo::class, $system);
        $this->alive();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-11-23 {$time}", 'Europe/Kyiv'));
    }

    private function alive(array $bot = []): void
    {
        foreach (['worker', 'reminders', 'addresses', 'bot'] as $service) {
            app(StatusFiles::class)->write($service, ['beat' => now()->getTimestamp(), ...($service === 'bot' ? $bot : [])]);
        }
    }

    /** @return list<string> */
    private function keys(Checks $checks): array
    {
        return array_column($checks->run(), 'key');
    }

    public function test_healthy_server_has_no_problems(): void
    {
        $this->assertSame([], $this->keys(app(Checks::class)));
        $this->resources = ['disk' => null, 'memory' => null, 'load' => null];
        $this->assertSame([], $this->keys(app(Checks::class)));
    }

    public function test_resources(): void
    {
        $checks = app(Checks::class);
        $this->resources = ['disk' => 1000, 'memory' => 50, 'load' => 3.0];
        $this->assertSame(['disk'], $this->keys($checks));

        $this->travel(5)->minutes();
        $this->alive();
        $this->resources['disk'] = 400;
        $this->assertSame(['disk', 'disk_critical', 'memory', 'load'], $this->keys($checks));

        // A short peak is not a problem.
        $this->resources = ['disk' => 5000, 'memory' => 400, 'load' => 0.1];
        $this->assertSame([], $this->keys($checks));
        $this->resources['load'] = 3.0;
        $this->assertSame([], $this->keys($checks));
    }

    public function test_silent_and_restarting_processes(): void
    {
        $checks = app(Checks::class);
        File::delete("{$this->dir}/worker.json");
        $this->assertSame([], $this->keys($checks));
        $this->travel(120)->seconds();
        app(StatusFiles::class)->write('bot', ['beat' => now()->getTimestamp(), 'starts' => [now()->getTimestamp() - 700, now()->getTimestamp() - 60, now()->getTimestamp() - 30, now()->getTimestamp()]]);
        $this->assertSame(['hang:worker', 'hang:reminders', 'hang:addresses', 'starts:bot'], $this->keys($checks));
    }

    public function test_bot_notices_a_silent_monitor(): void
    {
        $checks = app(Checks::class);
        $this->assertSame([], $checks->monitor());
        $this->travel(299)->seconds();
        $this->assertSame([], $checks->monitor());
        $this->travel(1)->seconds();
        $this->assertSame('monitor', $checks->monitor()[0]->key);
        app(StatusFiles::class)->write('monitor', ['beat' => now()->getTimestamp()]);
        $this->assertSame([], $checks->monitor());
    }

    public function test_work_of_processes(): void
    {
        $this->alive(['lag' => 10, 'backlog' => 29, 'flood' => 5, 'errors' => 10, 'database_errors' => 2]);
        $this->assertSame([], $this->keys(app(Checks::class)));
        $this->alive(['lag' => 11, 'flood' => 6, 'errors' => 11, 'database_errors' => 3]);
        $this->assertSame(['reply_lag', 'flood', 'errors', 'database_errors'], $this->keys(app(Checks::class)));
        $this->alive(['backlog' => 30]);
        $this->assertSame(['reply_lag'], $this->keys(app(Checks::class)));
    }

    public function test_late_deliveries_and_a_broken_database(): void
    {
        $create = fn (string $key, array $extra = []) => Delivery::query()->create(['telegram_id' => 5, 'dedupe_key' => $key, 'text' => 't', 'available_at' => now()->subMinutes(6), ...$extra]);
        $create('expired', ['expires_at' => now()->subMinute()]);
        $create('sent', ['status' => Delivery::SENT]);
        $create('retry', ['available_at' => now()->addMinute()]);
        $this->assertSame([], $this->keys(app(Checks::class)));
        $create('late');
        $this->assertSame(['delivery_lag'], $this->keys(app(Checks::class)));

        Schema::drop('deliveries');
        $this->assertSame(['database'], $this->keys(app(Checks::class)));
    }

    public function test_process_reports_its_state(): void
    {
        $status = app(ProcessStatus::class);
        $read = fn () => app(StatusFiles::class)->read('bot');
        $status->beat();
        $status->start('bot');
        $status->start('bot');
        $this->assertCount(2, $read()['starts']);

        // Messages received right after a start are late because of the pause.
        $status->lag(500, 100);
        $this->travel(60)->seconds();
        $status->lag(12, 3);
        $status->flood(5);
        $status->flood(5);
        $status->flood(6);
        Log::error('Update failed: SQLSTATE[HY000]: General error: 5 database is locked');
        Log::error('other');
        Log::warning('not an error');
        $status->beat();
        $this->assertEquals(['flood' => 2, 'errors' => 2, 'database_errors' => 1, 'lag' => 12, 'backlog' => 3],
            array_intersect_key($read(), array_flip(['flood', 'errors', 'database_errors', 'lag', 'backlog'])));

        $this->travel(301)->seconds();
        $status->beat();
        $this->assertSame([0, 0, 0], [$read()['flood'], $read()['errors'], $read()['lag']]);
    }

    private function update(array $problems): void
    {
        // State survives a restart of the process.
        app(Alerts::class)->update('alerts', $problems);
    }

    public function test_problem_is_reported_once_an_hour_and_when_it_is_over(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => []]));
        $problem = new Problem('x', 'Проблема', 'a < b');
        $texts = fn () => Http::recorded()->map(fn ($pair) => $pair[0]['text'])->all();

        $this->update([$problem]);
        $this->travel(59)->minutes();
        $this->update([$problem]);
        $this->assertCount(1, $texts());
        $this->assertStringContainsString('a &lt; b', $texts()[0]);
        $this->travel(1)->minutes();
        $this->update([$problem]);
        $this->assertCount(2, $texts());

        $this->update([]);
        $this->update([]);
        $this->assertCount(3, $texts());
        $this->assertStringContainsString('знову в нормі', $texts()[2]);

        // A flapping problem does not flood the admin.
        $this->update([$problem]);
        $this->update([]);
        $this->assertCount(3, $texts());
        $this->travel(61)->minutes();
        $this->update([$problem]);
        $this->assertCount(4, $texts());
    }

    public function test_only_critical_problems_sound_at_night(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => []]));
        $silent = fn () => Http::recorded()->last()[0]['disable_notification'];
        $cases = [['22:59', false, false], ['23:00', false, true], ['23:00', true, false], ['06:59', false, true], ['07:00', false, false]];

        foreach ($cases as $i => [$time, $critical, $expected]) {
            $this->at($time);
            $this->update([new Problem("x{$i}", 't', 'd', $critical)]);
            // Problems of the previous cases are over, the new one is the first message.
            $sent = Http::recorded(fn ($r) => str_contains($r['text'], '⚠️'))->last()[0];
            $this->assertSame($expected, $sent['disable_notification'], "{$time} #{$i}");
        }
        $this->assertFalse($silent());
    }

    public function test_failed_alert_is_sent_again(): void
    {
        Http::fakeSequence()->push(['ok' => false, 'error_code' => 500, 'description' => 'x'], 500)->push(['ok' => true, 'result' => []]);
        $this->update([new Problem('x', 't', 'd')]);
        $this->update([new Problem('x', 't', 'd')]);
        Http::assertSentCount(2);
    }

    public function test_command_works_without_a_database(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => []]));
        Schema::drop('deliveries');
        $this->artisan('app:monitor', ['--once' => true])->assertSuccessful();
        Http::assertSent(fn ($r) => $r['chat_id'] === 999 && str_contains($r['text'], 'База даних недоступна'));
        $this->assertNotEmpty(app(StatusFiles::class)->read('monitor')['beat']);
    }

    public function test_monitoring_is_off_without_a_path(): void
    {
        config(['monitor.path' => null]);
        app(ProcessStatus::class)->start('bot');
        $this->assertSame([], app(StatusFiles::class)->read('bot'));
    }
}
