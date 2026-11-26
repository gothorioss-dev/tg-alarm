<?php

namespace App\Telegram;

use App\Address\Change;
use App\Address\CityRegistry;
use App\Address\Kind;
use App\Models\Address;
use App\Models\AddressEvent;
use App\Models\City;
use App\Models\Street;
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

    public function __construct(private SourceRegistry $sources, private CityRegistry $cities) {}

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

    /** A text about outages without a list of queues. */
    public function notice(string $text, string $source, string $url): string
    {
        return "ℹ️ <b>Повідомлення про графік</b>\n\n".e(mb_strimwidth($text, 0, 3000, '…'))
            ."\n\nГрафік у боті може бути неактуальним.".$this->footer($source, $url);
    }

    /** @param list<string> $queues */
    public function currentSchedule(OutageTimeline $timeline, array $queues, ?string $checkedAt = null): string
    {
        if ($queues === []) {
            return 'Черги ще не обрано.';
        }

        $today = $timeline->now()->format('Y-m-d');
        $minute = $timeline->now()->hour * 60 + $timeline->now()->minute;
        $parts = [];

        foreach ($timeline->days() as $date => $day) {
            $parts[] = '📅 <b>'.($date === $today ? 'Сьогодні' : 'Завтра').", {$this->date($date)}</b>\n\n"
                .implode("\n\n", array_map(fn (string $q) => $this->queue($q, $day, $date === $today ? $minute : null), $queues));
        }

        if ($parts === []) {
            $parts[] = 'Графіків на сьогодні та завтра поки немає. Коли їх опублікують, я повідомлю.';
        } else {
            $next = array_filter(array_map(fn (string $q) => $this->next($q, $timeline, count($queues) > 1), $queues));

            if ($next !== []) {
                $parts[] = "⏰ <b>Наступне за графіком</b>\n".implode("\n", $next);
            }
        }
        if ($checkedAt !== null) {
            $parts[] = '<i>Дані на '.CarbonImmutable::parse($checkedAt, 'UTC')->setTimezone(config('telegram.timezone'))->format('H:i, d.m').'</i>';
        }

        return implode("\n\n", $parts);
    }

    /** @param int|null $minute current minute of the day: outages that are over get a mark */
    public function queue(string $queue, DaySchedule $day, ?int $minute = null): string
    {
        return "<b>Черга {$queue}</b>\n".$this->outages($queue, $day, $minute);
    }

    public function chooseQueue(): string
    {
        return "<b>Оберіть чергу</b>\n\nНатисніть на номер своєї черги — з'явиться ✅. Потім натисніть «💾 Готово». Можна обрати до {$this->maxQueues()} черг.\n\n"
            .'Не знаєте свою чергу? Подивіться <a href="'.e(config('telegram.queue_lookup_url')).'">на сайті Запоріжжяобленерго</a>.'
            ."\n\nПотрібен весь графік? Дивіться у джерелі:\n".$this->sourceLinks();
    }

    /** @param bool $addresses outages by address are available */
    public function guide(bool $addresses): string
    {
        return "📖 <b>Довідник</b>\n\n"
            ."Бот надсилає графік відключень світла для вашої черги.\n\n"
            ."<b>Як почати</b>\n"
            ."1. Натисніть на номер своєї черги — з'явиться ✅. Можна обрати до {$this->maxQueues()} черг.\n"
            ."2. Натисніть «💾 Готово».\n"
            ."Далі бот сам повідомить про новий графік і зміни.\n\n"
            ."<b>Кнопки внизу</b>\n"
            ."📅 <b>Графік</b> — відключення на сьогодні та завтра\n"
            ."⚡ <b>Мої черги</b> — додати або прибрати чергу\n"
            .($addresses ? "🚨 <b>Відключення за адресою</b> — сповіщення про аварії та ремонти за вашою адресою ({$this->cityNames()})\n" : '')
            ."🔔 <b>Сповіщення</b> — звук і нагадування перед відключенням\n"
            ."📖 <b>Довідник</b> — ця підказка\n"
            ."👥 <b>Запросити</b> — надіслати бота рідним або сусідам\n\n"
            ."❗ <b>Важливо</b>\n"
            ."Це лише графік. Насправді світло можуть вимкнути або увімкнути в інший час. Бот не знає, чи є світло саме у вас.\n\n"
            ."Бот неофіційний. Джерела (якщо розходяться, перевага за верхнім):\n"
            .$this->sourceLinks();
    }

    public function invite(string $link): string
    {
        return "👥 <b>Запросити</b>\n\n"
            ."Натисніть кнопку нижче й оберіть, кому надіслати запрошення.\n\n"
            ."Людина відкриє посилання й натисне кнопку «Розпочати» внизу екрана.\n\n"
            ."Посилання: {$link}";
    }

    /** Text sent together with the invitation link. */
    public function inviteText(): string
    {
        return 'Бот повідомляє про графік відключень світла у Запорізькій області. Відкрийте посилання й натисніть кнопку «Розпочати» внизу екрана.';
    }

    /** Shown in the empty chat before the start, up to 512 characters. */
    public function description(): string
    {
        return "Надсилаю графік відключень світла для вашої черги у Запорізькій області: новий графік, зміни та нагадування.\n\n"
            .'👇 Натисніть кнопку «Розпочати» внизу екрана.';
    }

    /** Shown in the bot profile and in shared links, up to 120 characters. */
    public function shortDescription(): string
    {
        return 'Графік відключень світла у Запорізькій області: зміни та нагадування для вашої черги. Неофіційний бот.';
    }

    /** @param array{total: int, active: int, new: int, reminders: int, silent: int, queues: array<string, int>, failing: list<string>} $stats */
    public function statistics(array $stats): string
    {
        $queues = [];

        foreach ($stats['queues'] as $queue => $count) {
            $queues[] = "{$queue} — {$count}";
        }

        return "📊 <b>Статистика</b>\n\n"
            ."Користувачів: <b>{$stats['total']}</b>, отримують: <b>{$stats['active']}</b>\n"
            ."Нових за 7 днів: {$stats['new']}\n"
            ."З нагадуваннями: {$stats['reminders']}, без звуку: {$stats['silent']}\n\n"
            ."<b>Черги</b>\n".($queues === [] ? 'немає' : implode("\n", $queues))."\n\n"
            .'Джерела: '.($stats['failing'] === [] ? '✅ працюють' : '⚠️ збій — '.e(implode(', ', $stats['failing'])));
    }

    public function welcomeBack(): string
    {
        return '👋 Вітаю! Ваші черги збережено. Перевірте їх нижче.';
    }

    public function menuUpdated(): string
    {
        return 'Кнопки внизу оновлено 👇';
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

    public function settings(bool $silent, ?int $remindMinutes): string
    {
        return "🔔 <b>Сповіщення</b>\n\n"
            .'Звук: '.($silent ? '🔇 без звуку' : '🔊 зі звуком')."\n"
            .'Нагадування перед відключенням: '.$this->reminderLabel($remindMinutes)
            ."\n\nНижче можна змінити. Цифри — за скільки хвилин нагадувати.\n"
            .'Новий графік і зміни надходять завжди.';
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

    private function outages(string $queue, DaySchedule $day, ?int $minute = null): string
    {
        $intervals = $day->intervals($queue);

        if ($intervals === []) {
            return 'Відключень за графіком немає';
        }

        return implode("\n", array_map(
            fn (Interval $interval) => $this->line($interval).($minute !== null && $interval->to <= $minute ? ' (минуло)' : ''),
            $intervals,
        ));
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

            return $this->line($interval).$note;
        }, $intervals));
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

    private function line(Interval $interval): string
    {
        return '• '.str_replace('–', ' до ', "з {$interval}");
    }

    /** Shown in an alert: up to 200 characters, no links. */
    public function tooManyQueues(): string
    {
        return "Можна обрати до {$this->maxQueues()} черг. Бот — для тих, кому потрібні кілька конкретних черг. "
            .'Повний графік дивіться у джерелі: посилання на цьому екрані.';
    }

    public function tooManyActions(): string
    {
        $seconds = (int) config('telegram.rate_limit.seconds');

        return "⏳ Забагато запитів. Зачекайте {$seconds} с і спробуйте ще раз.";
    }

    private function maxQueues(): int
    {
        return (int) config('telegram.max_queues');
    }

    /** @param list<Address> $addresses with active events */
    public function addresses(array $addresses): string
    {
        $text = "🚨 <b>Відключення за адресою</b>\nСповіщення про аварійні та ремонтні відключення\n\n";

        if ($addresses === []) {
            return $text."Додайте адресу — і я повідомлю про аварію, терміновий або плановий ремонт саме за нею. На графік за чергою це не впливає.\n\n"
                .$this->experimental()."\n\n"
                ."Поки що доступно: {$this->cityNames()}. Можна додати до ".config('addresses.max_addresses').' адрес.';
        }

        $blocks = array_map(function (Address $address) {
            $events = $address->events->whereNull('ended_at');
            $lines = $events->take(5)->map(fn (AddressEvent $event) => $this->addressEvent($event))->all();

            if ($events->count() > 5) {
                $lines[] = 'Ще: '.($events->count() - 5);
            }

            return '<b>'.e($this->addressLabel($address)).'</b>'
                .($address->house_known === false ? "\n⚠️ Будинку немає у списку обленерго, перевірте номер." : '')
                ."\n".($lines === [] ? 'Відключень у списку немає' : implode("\n", $lines));
        }, $addresses);

        return $text.implode("\n\n", $blocks)."\n\n".$this->checkedAt($addresses)."Графік за чергами тут не враховано.\n".$this->cityFooter($addresses[0]->city);
    }

    public function addressDraft(string $step, ?string $problem, ?Street $street): string
    {
        $title = "🚨 <b>Нова адреса</b>\n\n".$this->experimental()."\n\n";
        $hint = match ($problem) {
            'unknown' => "Такої вулиці не знайдено. Перевірте назву й спробуйте ще раз.\n\n",
            'many' => "Знайдено забагато вулиць. Напишіть назву повніше.\n\n",
            'loading' => "Список вулиць ще завантажується. Спробуйте за кілька хвилин.\n\n",
            'house' => "Не вдалося розпізнати номер будинку.\n\n",
            default => '',
        };

        return $title.$hint.match ($step) {
            'city' => 'Оберіть місто.',
            'choose' => 'Оберіть вулицю. Якщо її тут немає, напишіть назву інакше.',
            'house' => 'Вулиця: <b>'.e(trim((string) $street?->name))."</b>\n\nНапишіть номер будинку, наприклад: 12а",
            default => "Напишіть вулицю та будинок одним повідомленням, наприклад:\n<code>Соборний 150</code>\n\nСтара назва вулиці теж підійде. Номер квартири не потрібен.",
        };
    }

    public function addressAdded(): string
    {
        return 'Адресу збережено';
    }

    public function addressRemoved(): string
    {
        return 'Адресу видалено';
    }

    public function tooManyAddresses(): string
    {
        return 'Можна додати до '.config('addresses.max_addresses').' адрес. Щоб додати іншу, спочатку видаліть одну з наявних.';
    }

    /**
     * @param  non-empty-list<array{Address, AddressEvent}>  $items
     * @param  array{starts_at?: CarbonImmutable, ends_at?: ?CarbonImmutable, kind?: string}  $old
     */
    public function addressChange(Change $change, array $items, array $old, bool $others): string
    {
        $event = $items[0][1];
        $kind = $this->addressKind($event->kind);
        $title = match ($change) {
            Change::Appeared => ($event->starts_at->isFuture() ? '🗓' : '🚨')." <b>{$kind}</b>",
            Change::Started => "🚨 <b>Почалося: {$kind}</b>",
            Change::Changed => isset($old['kind']) ? "🔄 <b>Змінено: {$kind}</b>" : "🔄 <b>Змінено час: {$kind}</b>",
            Change::Cancelled => "✅ <b>Скасовано: {$kind}</b>",
            Change::Ended => "✅ <b>Завершено: {$kind}</b>",
        };
        $time = match ($change) {
            Change::Changed => 'Було: '.(isset($old['kind']) ? $this->addressKind($old['kind']).', ' : '')
                .$this->addressTime($old['starts_at'] ?? $event->starts_at, $old['ends_at'] ?? null)
                ."\nТепер: ".(isset($old['kind']) ? "{$kind}, " : '').$this->addressTime($event->starts_at, $event->ends_at),
            default => $this->addressTime($event->starts_at, $event->ends_at),
        };
        $note = match ($change) {
            Change::Ended => "\n\nЗа повідомленням обленерго електропостачання відновлено.".($others ? ' Але за цією адресою діє інше відключення.' : ''),
            Change::Cancelled => "\n\nЗа повідомленням обленерго відключення за цією адресою не буде.",
            default => '',
        };

        return "{$title}\n{$this->addressLines($items)}\n\n{$time}{$note}\n\n".$this->cityFooter($items[0][0]->city);
    }

    /** @param non-empty-list<array{Address, AddressEvent}> $items */
    public function addressReminder(array $items, CarbonImmutable $now): string
    {
        $event = $items[0][1];

        return "⏰ <b>Через {$this->duration($now, $event->starts_at)}: {$this->addressKind($event->kind)}</b>\n"
            ."{$this->addressLines($items)}\n\n{$this->addressTime($event->starts_at, $event->ends_at)}\n\n".$this->cityFooter($items[0][0]->city);
    }

    /** @param list<array{Address, AddressEvent}> $items */
    private function addressLines(array $items): string
    {
        return implode("\n", array_map(
            fn (array $item) => '🏠 '.e($this->addressLabel($item[0])).($item[1]->note === null ? '' : ' ('.e($item[1]->note).')'),
            $items,
        ));
    }

    private function addressLabel(Address $address): string
    {
        $city = count($this->cities->codes()) > 1 ? $this->cities->name($address->city->code).', ' : '';

        return $city.trim($address->street->name).', '.$address->house;
    }

    private function addressEvent(AddressEvent $event): string
    {
        $isOver = $event->ends_at !== null && $event->ends_at->isPast();

        return '• '.$this->addressKind($event->kind).': '.$this->addressTime($event->starts_at, $event->ends_at)
            .match (true) {
                $isOver => ' (час минув, підтвердження відновлення немає)',
                $event->announced && $event->starts_at->isPast() => ' (мало початися, підтвердження немає)',
                default => '',
            }
        .($event->note === null ? '' : ' ('.e($event->note).')');
    }

    private function addressKind(string $kind): string
    {
        return match ($kind) {
            Kind::Emergency->value => 'Аварійне відключення',
            Kind::Urgent->value => 'Терміновий ремонт',
            Kind::Planned->value => 'Плановий ремонт',
            default => 'Відключення',
        };
    }

    private function addressTime(CarbonImmutable $start, ?CarbonImmutable $end): string
    {
        $start = $start->setTimezone(config('telegram.timezone'));
        $text = "{$this->date($start->format('Y-m-d'))}, з {$start->format('H:i')}";

        return $end === null
            ? $text.', час відновлення невідомий'
            : $text.', орієнтовно до '.$this->endTime($end->setTimezone(config('telegram.timezone')), $start);
    }

    /** @param non-empty-list<Address> $addresses */
    private function checkedAt(array $addresses): string
    {
        $checkedAt = $addresses[0]->city->succeeded_at;

        return $checkedAt === null ? '' : '<i>Дані на '.$checkedAt->setTimezone(config('telegram.timezone'))->format('H:i, d.m')."</i>\n";
    }

    private function cityFooter(City $city): string
    {
        return '<a href="'.e($this->cities->page($city->code)).'">Джерело: '.e($this->cities->label($city->code)).'</a>';
    }

    private function cityNames(): string
    {
        return e(implode(', ', array_map($this->cities->name(...), $this->cities->codes())));
    }

    /** Shown while there is no selected queue. Up to 200 characters, no tags. */
    public function queueFirst(): string
    {
        return 'Спочатку оберіть свою чергу — без неї інші функції недоступні.';
    }

    private function experimental(): string
    {
        return '🧪 <b>Експериментальна функція.</b> Дані можуть бути неповними або надходити із запізненням.';
    }
}
