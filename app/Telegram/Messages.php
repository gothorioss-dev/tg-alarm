<?php

namespace App\Telegram;

use App\Schedule\DaySchedule;
use App\Schedule\Interval;
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

    /**
     * @param  array<string, DaySchedule>  $days
     * @param  list<string>  $queues
     */
    public function currentSchedule(array $days, array $queues, bool $active = true): string
    {
        if ($queues === []) {
            return 'Черги ще не обрано. Натисніть «⚙️ Черги».';
        }

        $note = $active ? '' : "\n\n🔕 Сповіщення вимкнено.";

        if ($days === []) {
            return 'Графіків на сьогодні та завтра поки немає.'.($active ? ' Коли їх опублікують, я повідомлю.' : $note);
        }

        $parts = [];

        foreach ($days as $date => $day) {
            $parts[] = "📅 <b>{$this->date($date)}</b>\n"
                .implode("\n", array_map(fn (string $q) => $this->queue($q, $day), $queues));
        }

        return implode("\n\n", $parts).$note;
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

    public function help(): string
    {
        return "Повідомляю про графіки відключень і зміни в них для обраних черг. Користуйтеся кнопками:\n\n"
            ."📅 <b>Графік</b> — на сьогодні та завтра\n"
            ."⚙️ <b>Черги</b> — обрати одну або кілька\n"
            ."🔕 / 🔔 — вимкнути або увімкнути сповіщення\n\n"
            .'У групі налаштування змінюють адміністратори.';
    }

    public function stopped(): string
    {
        return '🔕 Сповіщення вимкнено. Обрані черги збережено.';
    }

    public function adminsOnly(): string
    {
        return 'Змінювати налаштування можуть лише адміністратори групи.';
    }

    public function date(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return $day->day.' '.self::MONTHS[$day->month - 1];
    }

    public function welcome(): string
    {
        return '👋 Вітаю! Повідомлятиму про графіки відключень світла. Кнопки меню — внизу.';
    }

    public function enabled(): string
    {
        return '🔔 Сповіщення увімкнено.';
    }

    public function queueToggled(string $queue, bool $added): string
    {
        return ($added ? 'Додано чергу ' : 'Прибрано чергу ').$queue;
    }

    public function nothingSelected(): string
    {
        return 'Оберіть хоча б одну чергу.';
    }

    /** @param list<string> $queues */
    public function selected(array $queues): string
    {
        return '✅ '.(count($queues) === 1 ? 'Обрано чергу: ' : 'Обрано черги: ').'<b>'.implode(', ', $queues).'</b>';
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
}
