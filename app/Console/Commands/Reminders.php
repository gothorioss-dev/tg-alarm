<?php

namespace App\Console\Commands;

use App\Address\AddressReminders;
use App\Ingest\Reminders as ReminderQueue;
use App\Monitor\ProcessStatus;
use App\Telegram\DeliveryChannel;
use App\Telegram\DeliverySender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class Reminders extends Command
{
    protected $signature = 'app:reminders {--once : Run a single cycle}';

    protected $description = 'Send reminders and service alerts independently of broadcasts';

    public function handle(ReminderQueue $reminders, AddressReminders $addresses, DeliverySender $sender, ProcessStatus $status): int
    {
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });
        $status->start('reminders');
        // Docker restarts the process, which keeps memory bounded.
        $until = time() + 6 * 3600;

        do {
            foreach ([$reminders->queueDue(...), $addresses->queueDue(...), fn () => $sender->flush(DeliveryChannel::Urgent)] as $step) {
                try {
                    $step();
                } catch (Throwable $e) {
                    Log::error("Reminders step failed: {$e->getMessage()}");
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
