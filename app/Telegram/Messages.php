<?php

namespace App\Telegram;

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

    /** @param list<string> $queues */
    public function changedSchedule(string $date, DaySchedule $old, DaySchedule $new, array $queues, bool $correction, string $source, string $url): string
    {
        $title = $correction ? '⚠️ <b>Уточнення графіка на ' : '🔄 <b>Зміни в графіку на ';
        $blocks = [];

        foreach ($queues as $queue) {
            $block = $this->queue($queue, $new);

            if ($added = DaySchedule::subtract($new->intervals($queue), $old->intervals($queue))) {
                $block .= "\n➕ Додано відключення: ".$this->intervals($added);
            }
            if ($removed = DaySchedule::subtract($old->intervals($queue), $new->intervals($queue))) {
                $block .= "\n➖ Скасовано відключення: ".$this->intervals($removed);
            }
            $blocks[] = $block;
        }

        return $title.$this->date($date)."</b>\n\n".implode("\n\n", $blocks).$this->footer($source, $url);
    }

    public function emergency(string $text, string $source, string $url): string
    {
        return '🚨 <b>'.e(mb_strimwidth($text, 0, 3000, '…')).'</b>'.$this->footer($source, $url);
    }

    /** @param list<string> $queues */
    public function currentSchedule(OutageTimeline $timeline, array $queues, bool $active = true, ?string $checkedAt = null): string
    {
        if ($queues === []) {
            return 'Черги ще не обрано.';
        }

        $footer = $checkedAt === null ? '' : "\n\n<i>Дані перевірено о ".CarbonImmutable::parse($checkedAt, 'UTC')->setTimezone(config('telegram.timezone'))->format('H:i, d.m').'</i>';
        $footer .= $active ? '' : "\n🔕 Сповіщення вимкнено.";

        if ($timeline->days() === []) {
            return 'Графіків на сьогодні та завтра поки немає.'.($active ? ' Коли їх опублікують, я повідомлю.' : '').$footer;
        }

        $parts = [];

        if ($timeline->knowsToday()) {
            $parts[] = implode("\n", array_map(fn (string $q) => $this->status($q, $timeline), $queues));
        }
        foreach ($timeline->days() as $date => $day) {
            $parts[] = "📅 <b>{$this->date($date)}</b>\n"
                .implode("\n", array_map(fn (string $q) => $this->queue($q, $day), $queues));
        }

        return implode("\n\n", $parts).$footer;
    }

    public function queue(string $queue, DaySchedule $day): string
    {
        $intervals = $day->intervals($queue);

        return "<b>Черга {$queue}:</b> ".($intervals === [] ? '✅ без відключень' : $this->intervals($intervals));
    }

    public function chooseQueue(): string
    {
        return "Оберіть чергу (можна кілька). Надсилатиму графік відключень і зміни лише для обраних черг.\n\n"
            .'Свою чергу можна дізнатися <a href="'.e(config('telegram.queue_lookup_url')).'">на сайті Запоріжжяобленерго</a>.';
    }

    public function guide(): string
    {
        return "📖 <b>Довідник</b>\n\n"
            ."Позначте свою чергу (можна кілька) і натисніть «💾 Готово». Далі я сам повідомлятиму про новий графік, зміни для ваших черг та аварійні відключення.\n\n"
            ."Кнопки внизу:\n"
            ."📅 <b>Графік</b> — стан зараз і відключення на сьогодні та завтра\n"
            ."⚡ <b>Мої черги</b> — додати чергу; повторне натискання прибирає її\n"
            ."⚙️ <b>Налаштування</b> — сповіщення та нагадування перед відключенням\n\n"
            ."Це неофіційний бот, фактичні відключення можуть відрізнятися. Джерела (якщо розходяться, перевага за верхнім):\n"
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
            .($active ? '' : "\n\nПоки сповіщення вимкнено, нагадування теж не надходять.");
    }

    public function reminderLabel(?int $minutes): string
    {
        return $minutes === null ? 'вимкнено' : "за {$minutes} хв";
    }

    public function reminder(string $queue, Outage $outage, CarbonImmutable $now): string
    {
        return "⏰ <b>Черга {$queue}: відключення через {$this->duration($now, $outage->start)}</b>\n"
            ."З {$outage->start->format('H:i')} до {$this->endTime($outage->end, $outage->start)} за графіком.";
    }

    private function status(string $queue, OutageTimeline $timeline): string
    {
        $now = $timeline->now();

        if ($current = $timeline->current($queue)) {
            return "🔴 <b>{$queue}:</b> зараз відключення до {$this->endTime($current->end, $now)}";
        }

        $text = "🟢 <b>{$queue}:</b> зараз за графіком світло є";

        if ($next = $timeline->next($queue)) {
            $day = $next->start->isSameDay($now) ? '' : $this->date($next->start->format('Y-m-d')).' ';
            $text .= "\nНаступне відключення: {$day}о {$next->start->format('H:i')} (через {$this->duration($now, $next->start)})";
        }

        return $text;
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

    /** @param list<Interval> $intervals */
    private function intervals(array $intervals): string
    {
        return implode(', ', array_map('strval', $intervals));
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
