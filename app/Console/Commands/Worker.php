<?php

namespace App\Console\Commands;

use App\Backup\DailyBackup;
use App\Ingest\Poller;
use App\Monitor\ProcessStatus;
use App\Telegram\DeliveryCleanup;
use App\Telegram\DeliverySender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class Worker extends Command
{
    protected $signature = 'app:worker {--once : Run a single cycle}';

    protected $description = 'Poll schedule sources and send broadcasts';

    public function handle(Poller $poller, DeliverySender $sender, DailyBackup $backup, DeliveryCleanup $cleanup, ProcessStatus $status): int
    {
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });
        $status->start('worker');
        // Docker restarts the process, which keeps memory bounded.
        $until = time() + 6 * 3600;

        do {
            foreach ([$poller->pollDue(...), $sender->flush(...), $backup->runDue(...), $cleanup->runDue(...)] as $step) {
                try {
                    $step();
                } catch (Throwable $e) {
                    Log::error("Worker step failed: {$e->getMessage()}");
                }
                $status->beat();
            }

            if ($this->option('once')) {
                break;
            }
            Sleep::for(3)->seconds();
        } while ($running && time() < $until);

        return self::SUCCESS;
    }
}
