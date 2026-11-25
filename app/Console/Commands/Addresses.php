<?php

namespace App\Console\Commands;

use App\Address\AddressReminders;
use App\Address\CityPoller;
use App\Address\HouseDirectory;
use App\Monitor\ProcessStatus;
use App\Telegram\DeliveryChannel;
use App\Telegram\DeliverySender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class Addresses extends Command
{
    protected $signature = 'app:addresses {--once : Run a single cycle}';

    protected $description = 'Follow outages by address independently of schedules';

    public function handle(CityPoller $cities, HouseDirectory $houses, AddressReminders $reminders, DeliverySender $sender, ProcessStatus $status): int
    {
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });
        $status->start('addresses');
        // Docker restarts the process, which keeps memory bounded.
        $until = time() + 6 * 3600;
        $send = fn () => $sender->flush(DeliveryChannel::Address);

        do {
            foreach ([$reminders->queueDue(...), $send, $cities->pollDue(...), $send, $houses->checkDue(...)] as $step) {
                try {
                    $step();
                } catch (Throwable $e) {
                    Log::error("Addresses step failed: {$e->getMessage()}");
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
