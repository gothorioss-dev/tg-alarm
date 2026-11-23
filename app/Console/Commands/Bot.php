<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use App\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class Bot extends Command
{
    protected $signature = 'app:bot';

    protected $description = 'Handle Telegram updates via long polling';

    public function handle(TelegramClient $telegram, UpdateHandler $handler): int
    {
        $running = true;
        $this->trap([SIGTERM, SIGINT], function () use (&$running) {
            $running = false;
        });

        try {
            $telegram->call('getMe');
            // The bottom button replaces commands; without them Telegram hides its own menu button.
            $telegram->call('deleteMyCommands');
            $telegram->call('deleteMyCommands', ['scope' => ['type' => 'all_group_chats']]);
        } catch (TelegramException $e) {
            $this->error($e->getMessage());
            Sleep::for(10)->seconds();

            return self::FAILURE;
        }

        $offset = 0;
        // Docker restarts the process, which keeps memory bounded.
        $until = time() + 6 * 3600;

        while ($running && time() < $until) {
            try {
                $updates = $telegram->call('getUpdates', [
                    'offset' => $offset,
                    // A stop signal is handled only after the request returns, so it is kept short.
                    'timeout' => 10,
                    'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
                ], 20);
            } catch (TelegramException $e) {
                Log::warning("getUpdates failed: {$e->getMessage()}");
                Sleep::for(max(5, $e->retryAfter()))->seconds();

                continue;
            }

            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;

                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    Log::error("Update {$update['update_id']} failed: {$e->getMessage()}");
                }
            }
        }

        // Acknowledge processed updates so they are not handled again after a restart.
        if ($offset > 0) {
            rescue(fn () => $telegram->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'limit' => 1]), report: false);
        }

        return self::SUCCESS;
    }
}
