<?php

namespace App\Monitor;

use App\Address\CityRegistry;
use App\Models\City;
use App\Models\Delivery;
use Throwable;

/** Finds current problems of the server and processes. */
final class Checks
{
    private ?int $startedAt = null;

    /** @var array<string, int> start time of conditions that must last */
    private array $since = [];

    public function __construct(private SystemInfo $system, private StatusFiles $files, private CityRegistry $cities) {}

    /** @return list<Problem> */
    public function run(): array
    {
        $this->startedAt ??= $this->now();
        $statuses = [];

        foreach (config('monitor.services') as $service) {
            $statuses[$service] = $this->files->read($service);
        }

        return [...$this->resources(), ...$this->processes($statuses), ...$this->work($statuses), ...$this->deliveries(), ...$this->cities()];
    }

    /** @return list<Problem> the monitor has stopped reporting */
    public function monitor(): array
    {
        $this->startedAt ??= $this->now();

        if (! $this->isSilent($this->files->read('monitor'), (int) config('monitor.monitor_hang'))) {
            return [];
        }

        return [new Problem('monitor', 'Монітор не відповідає', 'Перевірки сервера не виконуються.', critical: true)];
    }

    /** @return list<Problem> */
    private function resources(): array
    {
        $problems = [];
        $disk = $this->system->freeDisk();
        $memory = $this->system->availableMemory();
        $load = $this->system->load();

        if ($disk !== null && $disk < config('monitor.disk')) {
            $problems[] = new Problem('disk', 'Мало місця на диску', "Вільно {$disk} МБ.", critical: true);
        }
        // A separate problem, so that it is reported even right after the first one.
        if ($disk !== null && $disk < config('monitor.disk_critical')) {
            $problems[] = new Problem('disk_critical', 'Місце на диску закінчується', "Вільно {$disk} МБ.", critical: true);
        }
        if ($this->lasts('memory', $memory !== null && $memory < config('monitor.memory'))) {
            $problems[] = new Problem('memory', 'Мало пам\'яті', "Доступно {$memory} МБ.");
        }
        if ($this->lasts('load', $load !== null && $load > config('monitor.load'))) {
            $problems[] = new Problem('load', 'Високе навантаження', 'Load average '.round($load, 1).'.');
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, mixed>>  $statuses
     * @return list<Problem>
     */
    private function processes(array $statuses): array
    {
        $problems = [];

        foreach ($statuses as $service => $status) {
            if ($this->isSilent($status, (int) config('monitor.hang'))) {
                $problems[] = new Problem("hang:{$service}", "Процес {$service} не відповідає", 'Немає сигналу понад '.config('monitor.hang').' с.', critical: true);
            }

            $starts = count(array_filter($status['starts'] ?? [], fn (int $time) => $this->now() - $time < ProcessStatus::STARTS_WINDOW));

            if ($starts >= config('monitor.starts')) {
                $problems[] = new Problem("starts:{$service}", "Процес {$service} часто перезапускається", "Запусків за 10 хв: {$starts}.");
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, mixed>>  $statuses
     * @return list<Problem>
     */
    private function work(array $statuses): array
    {
        // Numbers of a process that does not report are outdated.
        $alive = array_filter($statuses, fn (array $status) => $this->now() - ($status['beat'] ?? 0) < (int) config('monitor.hang'));
        $sum = fn (string $field) => array_sum(array_column($alive, $field));
        $bot = $alive['bot'] ?? [];
        [$lag, $backlog] = [$bot['lag'] ?? 0, $bot['backlog'] ?? 0];
        $problems = [];

        if ($lag > config('monitor.reply_lag') || $backlog >= config('monitor.reply_backlog')) {
            $problems[] = new Problem('reply_lag', 'Бот відповідає із затримкою', "Затримка {$lag} с, у черзі {$backlog}.");
        }
        if (($flood = $sum('flood')) > config('monitor.flood_users')) {
            $problems[] = new Problem('flood', 'Схоже на флуд', "Перевищили ліміт за 5 хв: {$flood} користувачів.");
        }
        if (($errors = $sum('errors')) > config('monitor.errors')) {
            $problems[] = new Problem('errors', 'Багато помилок', "За 5 хв: {$errors}. Подробиці в логах.");
        }
        if (($errors = $sum('database_errors')) >= config('monitor.database_errors')) {
            $problems[] = new Problem('database_errors', 'Помилки бази даних', "За 5 хв: {$errors}. Подробиці в логах.", critical: true);
        }

        return $problems;
    }

    /** @return list<Problem> */
    private function deliveries(): array
    {
        try {
            $late = Delivery::query()->where('status', Delivery::PENDING)
                ->where('available_at', '<', now()->subSeconds((int) config('monitor.delivery_lag')))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count();
        } catch (Throwable $e) {
            return [new Problem('database', 'База даних недоступна', mb_strimwidth($e->getMessage(), 0, 300, '…'), critical: true)];
        }

        return $late === 0 ? [] : [new Problem('delivery_lag', 'Розсилка відстає', 'Чекають понад '.intdiv((int) config('monitor.delivery_lag'), 60)." хв: {$late}.")];
    }

    /** @param array<string, mixed> $status */
    private function isSilent(array $status, int $seconds): bool
    {
        // Processes start at the same time, so a missing report is given the same time.
        return $this->now() - max($status['beat'] ?? 0, $this->startedAt) >= $seconds;
    }

    private function lasts(string $key, bool $condition): bool
    {
        if (! $condition) {
            unset($this->since[$key]);

            return false;
        }

        $this->since[$key] ??= $this->now();

        return $this->now() - $this->since[$key] >= (int) config('monitor.sustained');
    }

    /** @return list<Problem> */
    private function cities(): array
    {
        $problems = [];

        try {
            $cities = City::query()->whereIn('code', $this->cities->codes())->get();
        } catch (Throwable) {
            // An unavailable database is reported by another check.
            return [];
        }

        foreach ($cities as $city) {
            $name = $this->cities->name($city->code);
            $minutes = (int) config('addresses.stale_minutes');

            if (($city->succeeded_at ?? $city->created_at)->addMinutes($minutes)->isPast()) {
                $problems[] = new Problem("city:{$city->code}", "Немає даних про відключення за адресами: {$name}", "Останні дані отримано понад {$minutes} хв тому. Джерело недоступне або змінило формат.");
            }
            if ($city->shrunk > 0) {
                $problems[] = new Problem("city_shrunk:{$city->code}", "Підозріло короткий список відключень: {$name}", 'Завершення відключень поки не враховуються.');
            }
        }

        return $problems;
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }
}
