<?php

namespace App\Telegram;

use App\Schedule\ChangeImpact;
use App\Schedule\DaySchedule;
use App\Schedule\Interval;
use App\Schedule\Outage;
use App\Schedule\OutageTimeline;
use App\Sources\SourceRegistry;
use Carbon\CarbonImmutable;

/** User-facing texts (Ukrainian, Telegram HTML). */
final class Messages
{
    private const MONTHS = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];

    public function __construct(private SourceRegistry $sources) {}

    /** @param list<string> $queues */
    public function newSchedule(string $date, DaySchedule $day, array $queues, string $source, string $url): string
    {
        return "📅 <b>Графік відключень на {$this->date($date)}</b>\n\n"
            .implode("\n\n", array_map(fn (string $q) => $this->queue($q, $day), $queues))
            .$this->footer($source, $url);
    }

    /** @param array<string, ChangeImpact> $impacts by queue */
    public function changedSchedule(string $date, DaySchedule $new, array $impacts, bool $correction, string $source, string $url, CarbonImmutable $publishedAt): string
    {
        $title = $correction ? "⚠️ <b>Графік на {$this->date($date)} уточнено</b>" : "🔄 <b>Графік на {$this->date($date)} змінено</b>";
        $publishedAt = $publishedAt->setTimezone(config('telegram.timezone'));
        $published = $publishedAt->format('H:i');
        $minute = $publishedAt->format('Y-m-d') === $date ? $publishedAt->hour * 60 + $publishedAt->minute : null;
        $late = array_filter($impacts, fn (ChangeImpact $impact) => $impact->startedBy($minute));
        $title .= $late === []
            ? "\nОпубліковано о {$published}"
            : "\n❗ <b>Зміну опубліковано із запізненням, о {$published}</b>";
        $blocks = [];

        foreach ($impacts as $queue => $impact) {
            $block = "<b>Черга {$queue}</b>";

            if ($impact->added !== []) {
                $block .= "\n➕ Додали відключення:\n".$this->list($impact->added, $impact->minute);
            }
            if ($impact->removed !== []) {
                $block .= "\n➖ Скасували відключення:\n".$this->list($impact->removed, $impact->minute);
            }

            $blocks[] = $block."\n\nТепер графік такий:\n".$this->outages((string) $queue, $new);
        }

        return $title."\n\n".implode("\n\n", $blocks).$this->footer($source, $url);
    }

    public function emergency(string $text, string $source, string $url): string
    {
        $note = preg_match('/скасован/ui', $text) ? '' : "\n\nПід час аварійних відключень звичайний графік може не діяти.";

        return "🚨 <b>Аварійні відключення</b>\n\n".e(mb_strimwidth($text, 0, 3000, '…')).$note.$this->footer($source, $url);
    }

    /** @param list<string> $queues */
    public function currentSchedule(OutageTimeline $timeline, array $queues, bool $active = true, ?string $checkedAt = null): string
    {
        if ($queues === []) {
            return 'Черги ще не обрано.';
        }

        $today = $timeline->now()->format('Y-m-d');
        $parts = [];

        foreach ($timeline->days() as $date => $day) {
            $parts[] = '📅 <b>'.($date === $today ? 'Сьогодні' : 'Завтра').", {$this->date($date)}</b>\n\n"
                .implode("\n\n", array_map(fn (string $q) => $this->queue($q, $day), $queues));
        }

        if ($parts === []) {
            $parts[] = 'Графіків на сьогодні та завтра поки немає.'.($active ? ' Коли їх опублікують, я повідомлю.' : '');
        } else {
            $next = array_filter(array_map(fn (string $q) => $this->next($q, $timeline, count($queues) > 1), $queues));

            if ($next !== []) {
                $parts[] = "⏰ <b>Наступне за графіком</b>\n".implode("\n", $next);
            }
            $parts[] = '<i>'.$this->disclaimer().'</i>';
        }
        if ($checkedAt !== null) {
            $parts[] = '<i>Дані перевірено о '.CarbonImmutable::parse($checkedAt, 'UTC')->setTimezone(config('telegram.timezone'))->format('H:i, d.m').'</i>';
        }
        if (! $active) {
            $parts[] = '🔕 Сповіщення вимкнено.';
        }

        return implode("\n\n", $parts);
    }

    public function queue(string $queue, DaySchedule $day): string
    {
        return "<b>Черга {$queue}</b>\n".$this->outages($queue, $day);
    }

    public function chooseQueue(): string
    {
        return "<b>Оберіть чергу</b>\n\nНатисніть на номер своєї черги — з'явиться ✅. Потім натисніть «💾 Готово». Можна обрати кілька.\n\n"
            .'Не знаєте свою чергу? Подивіться <a href="'.e(config('telegram.queue_lookup_url')).'">на сайті Запоріжжяобленерго</a>.';
    }

    public function guide(): string
    {
        return "📖 <b>Довідник</b>\n\n"
            ."Бот надсилає графік відключень світла для вашої черги.\n\n"
            ."<b>Як почати</b>\n"
            ."1. Натисніть на номер своєї черги — з'явиться ✅. Можна обрати кілька.\n"
            ."2. Натисніть «💾 Готово».\n"
            ."Далі бот сам повідомить про новий графік і зміни.\n\n"
            ."<b>Кнопки внизу</b>\n"
            ."📅 <b>Графік</b> — відключення на сьогодні та завтра\n"
            ."⚡ <b>Мої черги</b> — додати або прибрати чергу\n"
            ."⚙️ <b>Налаштування</b> — сповіщення та нагадування перед відключенням\n"
            ."📖 <b>Довідник</b> — ця підказка\n\n"
            ."❗ <b>Важливо</b>\n"
            .$this->disclaimer()." Бот не знає, чи є світло саме у вас.\n\n"
            ."Бот неофіційний. Джерела (якщо розходяться, перевага за верхнім):\n"
            .$this->sourceLinks();
    }

    public function menuHint(): string
    {
        return 'Кнопки — внизу 👇';
    }

    public function date(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return $day->day.' '.self::MONTHS[$day->month - 1];
    }

    public function queueToggled(string $queue, bool $added): string
    {
        return ($added ? 'Додано чергу ' : 'Прибрано чергу ').$queue;
    }

    public function nothingSelected(): string
    {
        return 'Оберіть хоча б одну чергу.';
    }

    public function settings(bool $active, ?int $remindMinutes): string
    {
        return "⚙️ <b>Налаштування</b>\n\n"
            .'Сповіщення: '.($active ? '🔔 увімкнено' : '🔕 вимкнено')."\n"
            .'Нагадування перед відключенням: '.$this->reminderLabel($remindMinutes)
            .($active ? '' : "\n\nПоки сповіщення вимкнено, нагадування теж не надходять.")
            ."\n\nНижче оберіть, за скільки хвилин нагадувати:";
    }

    public function reminderLabel(?int $minutes): string
    {
        return $minutes === null ? 'вимкнено' : "за {$minutes} хв";
    }

    public function reminder(string $queue, Outage $outage, CarbonImmutable $now): string
    {
        return "⏰ <b>За графіком через {$this->duration($now, $outage->start)} відключення</b>\n"
            ."Черга {$queue}: з {$outage->start->format('H:i')} до {$this->endTime($outage->end, $outage->start)}";
    }

    private function next(string $queue, OutageTimeline $timeline, bool $withQueue): ?string
    {
        $next = $timeline->next($queue);

        if ($next === null) {
            return null;
        }

        $now = $timeline->now();
        $day = $next->start->isSameDay($now) ? '' : $this->date($next->start->format('Y-m-d')).' ';

        return ($withQueue ? "Черга {$queue}: " : '')
            ."{$day}з {$next->start->format('H:i')} до {$this->endTime($next->end, $next->start)} (через {$this->duration($now, $next->start)})";
    }

    private function outages(string $queue, DaySchedule $day): string
    {
        $intervals = $day->intervals($queue);

        return $intervals === [] ? 'Відключень за графіком немає' : $this->list($intervals);
    }

    /**
     * One outage per line, with a note on the time that has already started.
     *
     * @param  list<Interval>  $intervals
     */
    private function list(array $intervals, ?int $minute = null): string
    {
        return implode("\n", array_map(function (Interval $interval) use ($minute) {
            $note = match (true) {
                $minute === null || $interval->from > $minute => '',
                $interval->to <= $minute => ' (цей час уже минув)',
                default => ' (цей час уже почався)',
            };

            return '• '.str_replace('–', ' до ', "з {$interval}").$note;
        }, $intervals));
    }

    private function disclaimer(): string
    {
        return 'Це лише графік. Насправді світло можуть вимкнути або увімкнути в інший час.';
    }

    /** Midnight right after the reference day is shown as 24:00, as in the source. */
    private function endTime(CarbonImmutable $end, CarbonImmutable $reference): string
    {
        if ($end->isSameDay($reference)) {
            return $end->format('H:i');
        }
        if ($end->equalTo($reference->addDay()->startOfDay())) {
            return '24:00';
        }

        return $end->format('H:i').' ('.$this->date($end->format('Y-m-d')).')';
    }

    private function duration(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $minutes = max(1, (int) ceil($from->diffInSeconds($to) / 60));
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours ? "{$hours} год " : '').($rest ? "{$rest} хв" : ''));
    }

    private function footer(string $source, string $url): string
    {
        return "\n\n<a href=\"".e($url).'">Джерело: '.e($this->sources->label($source)).'</a>';
    }

    /** Enabled sources, the most trusted first. */
    private function sourceLinks(): string
    {
        $keys = $this->sources->keys();
        usort($keys, fn (string $a, string $b) => $this->sources->priority($b) <=> $this->sources->priority($a));

        return implode("\n", array_map(function (string $key) {
            $label = e($this->sources->label($key));
            $page = $this->sources->page($key);

            return '• '.($page === '' ? $label : '<a href="'.e($page)."\">{$label}</a>");
        }, $keys));
    }
}
