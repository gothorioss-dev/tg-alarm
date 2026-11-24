<?php

namespace App\Console\Commands;

use App\Monitor\Alerts;
use App\Monitor\Checks;
use App\Monitor\ProcessStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class Monitor extends Command
{
    protected $signature = 'app:monitor {--once : Run a single check}';

    protected $description = 'Watch the server and processes and alert the admin';

    public function handle(Checks $checks, Alerts $alerts, ProcessStatus $status): int
    {
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });
        $status->start('monitor');
        // Docker restarts the process, which keeps memory bounded.
        $until = time() + 6 * 3600;
        $next = 0;

        do {
            if (time() >= $next) {
                try {
                    $alerts->update('alerts', $checks->run());
                } catch (Throwable $e) {
                    Log::error("Monitor check failed: {$e->getMessage()}");
                }
                $next = time() + (int) config('monitor.interval');
            }

            if ($this->option('once')) {
                break;
            }
            $status->beat();
            Sleep::for(3)->seconds();
        } while ($running && time() < $until);

        return self::SUCCESS;
    }
}
