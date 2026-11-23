<?php

namespace Tests\Feature;

use App\Backup\DailyBackup;
use App\Models\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/zoe-backup-'.uniqid();
        mkdir($this->dir);
        $pdo = new PDO("sqlite:{$this->dir}/source.sqlite");
        $pdo->exec('create table t (v integer); insert into t values (42)');
        config(['backup.path' => $this->dir, 'database.connections.sqlite.database' => "{$this->dir}/source.sqlite"]);
        $this->at('2026-11-23 03:31');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Europe/Kyiv'));
    }

    private function names(): array
    {
        return array_map(fn ($f) => substr(basename($f), 6, 10), glob("{$this->dir}/daily-*.sqlite"));
    }

    public function test_readable_copy_is_made_once_a_day_after_the_set_time(): void
    {
        $backup = app(DailyBackup::class);
        $this->at('2026-11-23 03:29');
        $backup->runDue();
        $this->assertSame([], $this->names());

        $this->at('2026-11-23 03:30');
        $backup->runDue();
        $backup->runDue();
        $this->assertSame(['2026-11-23'], $this->names());
        $copy = new PDO("sqlite:{$this->dir}/daily-2026-11-23.sqlite");
        $this->assertSame(42, (int) $copy->query('select v from t')->fetchColumn());

        $this->at('2026-11-24 12:00');
        $backup->runDue();
        $this->assertCount(2, $this->names());
    }

    public function test_only_outdated_daily_copies_are_removed(): void
    {
        foreach (['2026-09-01', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-11-22'] as $date) {
            touch("{$this->dir}/daily-{$date}.sqlite");
        }
        touch("{$this->dir}/manual-2020-01-01.sqlite");
        app(DailyBackup::class)->runDue();

        $this->assertSame(['2026-10-24', '2026-10-25', '2026-11-22', '2026-11-23'], $this->names());
        $this->assertFileExists("{$this->dir}/manual-2020-01-01.sqlite");
    }

    public function test_newest_copies_survive_a_long_pause(): void
    {
        foreach (['2026-01-01', '2026-01-02', '2026-01-03'] as $date) {
            touch("{$this->dir}/daily-{$date}.sqlite");
        }
        app(DailyBackup::class)->runDue();

        $this->assertSame(['2026-01-02', '2026-01-03', '2026-11-23'], $this->names());
    }

    public function test_failure_alerts_admin_once_a_day_and_is_retried_later(): void
    {
        config(['backup.path' => "{$this->dir}/missing"]);
        $backup = app(DailyBackup::class);
        $backup->runDue();
        $backup->runDue();
        $this->assertSame(1, Delivery::query()->where('telegram_id', 999)->count());

        mkdir("{$this->dir}/missing");
        $backup->runDue();
        $this->assertCount(0, glob("{$this->dir}/missing/*"));
        $this->travel(61)->minutes();
        $backup->runDue();
        $this->assertCount(1, glob("{$this->dir}/missing/*"));
    }

    public function test_backups_are_off_without_a_path(): void
    {
        config(['backup.path' => null]);
        app(DailyBackup::class)->runDue();
        $this->assertSame([], $this->names());
        $this->assertSame(0, Delivery::query()->count());
    }
}
