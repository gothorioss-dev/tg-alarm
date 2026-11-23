<?php

namespace App\Console\Commands;

use App\Models\SourceState;
use Illuminate\Console\Command;

class Health extends Command
{
    protected $signature = 'app:health {--since= : Unix time after which the worker must have polled}';

    protected $description = 'Check that the worker polls the sources';

    public function handle(): int
    {
        // An attempt is enough: an unavailable source does not mean a broken release.
        $polledAt = SourceState::query()->get()->max('polled_at');
        $since = (int) ($this->option('since') ?: now()->subMinutes(5)->timestamp);

        if ($polledAt === null || $polledAt->timestamp < $since) {
            $this->error('The worker has not polled the sources');

            return self::FAILURE;
        }

        $this->info('OK');

        return self::SUCCESS;
    }
}
