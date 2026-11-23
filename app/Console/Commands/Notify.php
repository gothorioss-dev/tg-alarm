<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use Illuminate\Console\Command;

class Notify extends Command
{
    protected $signature = 'app:notify {text}';

    protected $description = 'Send a service message to the admin right away';

    public function handle(TelegramClient $telegram): int
    {
        $adminId = (int) config('telegram.admin_id');

        if ($adminId === 0) {
            return self::SUCCESS;
        }

        try {
            $telegram->send($adminId, '🛠 '.e($this->argument('text')));
        } catch (TelegramException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
