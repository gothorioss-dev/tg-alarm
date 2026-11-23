<?php

namespace App\Console\Commands;

use App\Ingest\Poller;
use App\Ingest\Reminders;
use App\Telegram\DeliverySender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class Worker extends Command
{
    protected $signature = 'app:worker {--once : Run a single cycle}';

    protected $description = 'Poll schedule sources and deliver notifications';

    public function handle(Poller $poller, Reminders $reminders, DeliverySender $sender): int
    {
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });
        // Docker restarts the process, which keeps memory bounded.
        $until = time() + 6 * 3600;

        do {
            foreach ([$poller->pollDue(...), $reminders->queueDue(...), $sender->flush(...)] as $step) {
                try {
                    $step();
                } catch (Throwable $e) {
                    Log::error("Worker step failed: {$e->getMessage()}");
                }
            }

            if ($this->option('once')) {
                break;
            }
            Sleep::for(3)->seconds();
        } while ($running && time() < $until);

        return self::SUCCESS;
    }
}
