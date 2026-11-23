<?php

namespace Tests\Unit;

use App\Schedule\DaySchedule;
use App\Schedule\Interval;
use App\Schedule\Kind;
use App\Schedule\ScheduleFormatException;
use App\Schedule\ScheduleParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduleParserTest extends TestCase
{
    private function parse(string $list, string $at = '2026-11-23 08:51:40', string $head = 'оновлено ГПВ на 23 Листопада.')
    {
        return (new ScheduleParser)->parse($head."\n".$list, CarbonImmutable::parse($at, 'UTC'));
    }

    public function test_dash_style_does_not_matter_and_missing_queue_is_empty(): void
    {
        $a = $this->parse("1.1: 07:00 - 12:00, 23:30 - 24:00\n2.1: 00:00 - 01:00");
        $b = $this->parse("1.1: 07:00 – 12:00, 23:30 – 24:00\n\n2.1: 00:00 – 01:00");

        $this->assertSame($a->schedule->hash(), $b->schedule->hash());
        $this->assertSame([], $a->schedule->intervals('2.2'));
        $this->assertSame('2026-11-23', $a->date);
    }

    public function test_year_and_declared_time_cross_midnight(): void
    {
        $p = $this->parse('1.1: 07:00 – 12:00', '2026-12-31 22:05:00', 'ОНОВЛЕНО ГПВ НА 1 СІЧНЯ (оновлено 23:50)');
        $this->assertSame('2027-01-01', $p->date);
        $this->assertSame('2026-12-31 21:50', $p->declaredAt->format('Y-m-d H:i'));

        $p = $this->parse('1.1: 07:00 – 12:00', '2026-12-31 20:00:00', '1 СІЧНЯ ПО ЗАПОРІЗЬКІЙ ОБЛАСТІ ДІЯТИМУТЬ ГПВ');
        $this->assertSame('2027-01-01', $p->date);
        $this->assertNull($p->declaredAt);
    }

    #[DataProvider('broken')]
    public function test_broken_lists_are_rejected(string $list): void
    {
        $this->expectException(ScheduleFormatException::class);
        $this->parse($list);
    }

    public static function broken(): array
    {
        return [
            'bad interval' => ["1.1: 07:00 – 12:00\n1.2: 25:00 – 26:00"],
            'words' => ["1.1: 07:00 – 12:00\n1.2: не вимикається"],
            'mangled queue' => ["1.1: 07:00 – 12:00\nчерга 1.2 07:00 – 12:00"],
            'unknown queue' => ["1.1: 07:00 – 12:00\n7.1: 07:00 – 12:00"],
            'duplicate' => ["1.1: 07:00 – 12:00\n1.1: 07:00 – 12:00"],
            'overlap' => ['1.1: 07:00 – 12:00, 11:00 – 13:00'],
            'empty' => [''],
            'two dates' => ["та 24 листопада\n1.1: 07:00 – 12:00"],
            'mangled first row' => ["черга 1.1 07:00 – 12:00\n1.2: 07:00 – 12:00"],
        ];
    }

    public function test_classification(): void
    {
        $parser = new ScheduleParser;
        $this->assertSame(Kind::Schedule, $parser->classify("оновлено ГПВ\n1.1: 07:00 – 12:00"));
        $this->assertSame(Kind::Emergency, $parser->classify('УВАГА! ДІЮТЬ СПЕЦІАЛЬНІ ГРАФІКИ АВАРІЙНИХ ВІДКЛЮЧЕНЬ, ГПВ'));
        $this->assertSame(Kind::Unknown, $parser->classify('ГПВ скасовано'));
        $this->assertSame(Kind::Other, $parser->classify('Електропостачання відновлено'));
    }

    public function test_interval_subtraction(): void
    {
        $diff = DaySchedule::subtract([new Interval(600, 900), new Interval(1000, 1100)], [new Interval(650, 700)]);
        $this->assertSame(['10:00–10:50', '11:40–15:00', '16:40–18:20'], array_map('strval', $diff));
    }
}
