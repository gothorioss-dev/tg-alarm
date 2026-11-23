<?php

namespace App\Backup;

use App\Ingest\Outbox;
use Carbon\CarbonImmutable;
use PDO;
use RuntimeException;
use Throwable;

/** Makes one database copy a day and removes outdated ones. */
final class DailyBackup
{
    private ?CarbonImmutable $nextAttempt = null;

    public function __construct(private Outbox $outbox) {}

    public function runDue(): void
    {
        $path = (string) config('backup.path');
        $now = CarbonImmutable::now(config('telegram.timezone'));

        if ($path === '' || $now->format('H:i') < config('backup.time') || $this->nextAttempt?->isFuture()) {
            return;
        }

        $file = "{$path}/daily-{$now->format('Y-m-d')}.sqlite";

        if (is_file($file)) {
            return;
        }

        try {
            $this->create($file);
            $this->rotate($path, $now);
        } catch (Throwable $e) {
            $this->nextAttempt = CarbonImmutable::now()->addMinutes((int) config('backup.retry_minutes'));
            $this->outbox->admin("backup:{$now->format('Y-m-d')}", "Не вдалося зробити резервну копію бази: {$e->getMessage()}");
        }
    }

    private function create(string $file): void
    {
        $temporary = "{$file}.tmp";

        if (is_file($temporary)) {
            unlink($temporary);
        }

        // VACUUM INTO makes a consistent copy while the bot is writing.
        $source = new PDO('sqlite:'.config('database.connections.sqlite.database'));
        $source->exec('PRAGMA busy_timeout = 10000');
        $source->exec('VACUUM INTO '.$source->quote($temporary));
        chmod($temporary, 0600);

        $check = (new PDO("sqlite:{$temporary}"))->query('PRAGMA integrity_check')->fetchColumn();

        if ($check !== 'ok') {
            unlink($temporary);

            throw new RuntimeException("integrity check failed: {$check}");
        }
        if (! rename($temporary, $file)) {
            throw new RuntimeException('cannot rename the copy');
        }
    }

    private function rotate(string $path, CarbonImmutable $now): void
    {
        $oldest = $now->subDays((int) config('backup.keep_days'))->format('Y-m-d');
        $files = glob("{$path}/daily-????-??-??.sqlite") ?: [];
        rsort($files);

        foreach (array_slice($files, (int) config('backup.keep_min')) as $file) {
            if (substr(basename($file, '.sqlite'), 6) < $oldest) {
                unlink($file);
            }
        }
    }
}
