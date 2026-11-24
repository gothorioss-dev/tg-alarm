<?php

namespace App\Monitor;

use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use Illuminate\Support\Facades\Log;

/** Reports problems to the admin directly, bypassing the database. */
final class Alerts
{
    /** @var array<string, array<string, array{sent: int, open: bool, title: string}>> reported problems by state name and key */
    private array $states = [];

    public function __construct(private TelegramClient $telegram, private StatusFiles $files) {}

    /**
     * @param  string  $name  name of the state, which survives a restart
     * @param  list<Problem>  $problems  all current problems
     */
    public function update(string $name, array $problems): void
    {
        $state = $this->states[$name] ??= $this->files->read($name);
        $now = now()->getTimestamp();
        $current = [];

        foreach ($problems as $problem) {
            $current[$problem->key] = true;

            if ($now - ($state[$problem->key]['sent'] ?? 0) < (int) config('monitor.repeat')) {
                continue;
            }
            // A failed message is sent again at the next check.
            if ($this->send("⚠️ <b>{$problem->title}</b>\n".e($problem->details), ! $problem->critical && $this->isQuiet())) {
                $state[$problem->key] = ['sent' => $now, 'open' => true, 'title' => $problem->title];
            }
        }

        foreach ($state as $key => $reported) {
            if (isset($current[$key])) {
                continue;
            }
            if ($reported['open'] && $this->send("✅ {$reported['title']}: знову в нормі", $this->isQuiet())) {
                $state[$key]['open'] = false;
            }
            if (! $state[$key]['open'] && $now - $reported['sent'] >= (int) config('monitor.repeat')) {
                unset($state[$key]);
            }
        }

        if ($state !== $this->states[$name]) {
            $this->states[$name] = $state;
            $this->files->write($name, $state);
        }
    }

    private function send(string $html, bool $silent): bool
    {
        $adminId = (int) config('telegram.admin_id');

        if ($adminId === 0) {
            Log::warning(strip_tags($html));

            return true;
        }

        try {
            $this->telegram->send($adminId, "🛠 {$html}", ['disable_notification' => $silent]);

            return true;
        } catch (TelegramException $e) {
            Log::warning("Alert was not sent: {$e->getMessage()}");

            return false;
        }
    }

    private function isQuiet(): bool
    {
        $time = now(config('telegram.timezone'))->format('H:i');

        return $time >= config('monitor.quiet.from') || $time < config('monitor.quiet.to');
    }
}
