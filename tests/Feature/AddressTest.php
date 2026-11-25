<?php

namespace Tests\Feature;

use App\Address\AddressReminders;
use App\Address\AddressText;
use App\Address\CityPoller;
use App\Address\CityRegistry;
use App\Models\Address;
use App\Models\AddressEvent;
use App\Models\Chat;
use App\Models\City;
use App\Models\CityOutage;
use App\Models\Delivery;
use App\Models\Street;
use App\Monitor\Checks;
use App\Telegram\Keyboards;
use App\Telegram\UpdateHandler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    private const STREETS = ['пр. Соборний', 'вул. Європейська (Малиновського)', 'вул. Лісова', 'пров. Лісова', 'вул. 8 Березня', 'вул. Рiздвяна (9 січня)'];

    /** @var list<array<string, string>> */
    private array $rows = [];

    private int $status = 200;

    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00', 'Europe/Kyiv'));
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'script.google.com')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 60]]);
            }
            $result = match (json_decode($request['request'])[0]) {
                'getOutageData' => $this->rows,
                'getStreetList' => self::STREETS,
                default => ['150', '152а', '10-20'],
            };

            return Http::response(")]}'\n\n".json_encode([['op.exec', [0, json_encode($result)]], ['di', 5]]), $this->status);
        });
        $this->chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private', 'active' => true, 'remind_minutes' => 30, 'keyboard_version' => Keyboards::version(5)]);
        $this->row('вул. Лісова', '1', 'Планова', '30.11.2026 09:00', '30.11.2026 17:00');
        $this->poll();
    }

    private function row(string $street, string $houses, string $kind, string $start, string $end = ' '): void
    {
        [$startDate, $startTime] = explode(' ', $start);
        [$endDate, $endTime] = explode(' ', $end);
        $this->rows[] = ['Будинки' => $houses, 'Вид робіт' => $kind, 'Вулиця' => $street, 'Дата початку' => $startDate, 'Час початку' => $startTime, 'Дата закінчення' => $endDate, 'Час закінчення' => $endTime];
    }

    private function poll(): void
    {
        $this->travel(2)->minutes();
        app(CityPoller::class)->pollDue();
    }

    private function follow(string $street, string $house, ?Chat $chat = null): Address
    {
        $street = Street::query()->where('key', AddressText::normalize($street))->sole();

        return Address::query()->create(['chat_id' => ($chat ?? $this->chat)->id, 'city_id' => $street->city_id, 'street_id' => $street->id, 'house' => $house, 'house_key' => $house]);
    }

    /** @return list<string> */
    private function texts(): array
    {
        return Delivery::query()->orderBy('id')->pluck('text')->all();
    }

    private function say(string $text): string
    {
        return $this->update(['message' => ['message_id' => 50, 'text' => $text, 'chat' => ['id' => 5, 'type' => 'private']]]);
    }

    private function press(string $data): string
    {
        return $this->update(['callback_query' => ['id' => 'x', 'data' => $data, 'message' => ['message_id' => 1, 'chat' => ['id' => 5, 'type' => 'private']]]]);
    }

    private function update(array $update): string
    {
        $this->travel(4)->seconds();
        app(UpdateHandler::class)->handle($update);

        return (string) Http::recorded()->map(fn ($pair) => $pair[0])->filter(fn ($r) => isset($r['text'], $r['chat_id']))->last()?->data()['text'];
    }

    public function test_emergency_is_reported_updated_and_ended(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '22-28', 'Аварійне відключення', '25.11.2026 11:50');
        $this->poll();
        $this->assertCount(1, $this->texts());
        $this->assertStringContainsString('Аварійне відключення', $this->texts()[0]);
        $this->assertStringContainsString('з 11:50, час відновлення невідомий', $this->texts()[0]);

        $this->rows = array_reverse($this->rows);
        $this->poll();
        $this->assertCount(1, $this->texts());

        $this->rows[0]['Дата закінчення'] = '25.11.2026';
        $this->rows[0]['Час закінчення'] = '18:00';
        $this->poll();
        $this->assertStringContainsString('Змінено час', $this->texts()[1]);
        $this->assertStringContainsString('до 18:00', $this->texts()[1]);

        array_shift($this->rows);
        $this->poll();
        $this->assertCount(2, $this->texts());
        $this->poll();
        $this->assertStringContainsString('Завершено', $this->texts()[2]);
        $this->poll();
        $this->assertCount(3, $this->texts());

        // The same outage has returned.
        $this->row('пр. Соборний', '24', 'Аварійне відключення', '25.11.2026 11:50');
        $this->poll();
        $this->assertCount(4, $this->texts());
        $this->assertSame(1, AddressEvent::query()->count());
    }

    public function test_one_outage_at_several_addresses_is_one_message(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->follow('вул. Лісова', '5');
        $this->row('пр. Соборний', '24', 'Термінова', '26.11.2026 13:00', '26.11.2026 17:00');
        $this->row('вул. Лісова', '5 (2 пов)', 'Термінова', '26.11.2026 13:00', '26.11.2026 17:00');
        $this->poll();
        $this->assertCount(1, $this->texts());
        $this->assertStringContainsString('Соборний, 24', $this->texts()[0]);
        $this->assertStringContainsString('Лісова, 5 (2 пов)', $this->texts()[0]);
    }

    public function test_short_gap_and_failures_do_not_end_outages(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '24', 'Аварійне відключення', '25.11.2026 11:50');
        $this->poll();
        $all = $this->rows;

        $this->rows = [$all[0]];
        $this->poll();
        $this->rows = $all;
        $this->poll();
        foreach ([[], [['x' => 1]]] as $broken) {
            $this->rows = $broken;
            $this->poll();
            $this->poll();
            $this->poll();
        }
        $this->status = 500;
        $this->rows = [$all[0]];
        $this->poll();
        $this->poll();
        $this->assertCount(1, $this->texts());
        $this->assertSame(8, City::query()->sole()->failures);

        $this->travel(30)->minutes();
        $this->assertContains('city:zaporizhzhia', array_column(app(Checks::class)->run(), 'key'));
    }

    public function test_shrunk_response_is_trusted_only_after_repeats(): void
    {
        config(['addresses.shrink.polls' => 3]);
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '24', 'Аварійне відключення', '25.11.2026 11:50');
        for ($i = 0; $i < 30; $i++) {
            $this->row('вул. Лісова', (string) $i, 'Планова', '27.11.2026 09:00', '27.11.2026 17:00');
        }
        $this->poll();
        $this->rows = array_slice($this->rows, 0, 1);
        // New outages are still reported.
        $this->row('пр. Соборний', '24', 'Планова', '28.11.2026 09:00', '28.11.2026 17:00');
        $this->poll();
        $this->assertCount(2, $this->texts());
        $this->assertContains('city_shrunk:zaporizhzhia', array_column(app(Checks::class)->run(), 'key'));
        $this->poll();
        $this->assertCount(2, $this->texts());
        $this->assertGreaterThan(30, CityOutage::query()->count());
        $this->poll();
        $this->poll();
        $this->assertStringContainsString('Завершено', $this->texts()[2]);
    }

    public function test_planned_outage_is_reminded_twice_and_again_after_a_move(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '24', 'Планова', '27.11.2026 09:00', '27.11.2026 17:00');
        $this->poll();
        $remind = function (string $time) {
            $this->travelTo(CarbonImmutable::parse($time, 'Europe/Kyiv'));
            app(AddressReminders::class)->queueDue();

            return count($this->texts());
        };
        $this->assertSame(1, $remind('2026-11-26 08:59'));
        $this->assertSame(2, $remind('2026-11-26 09:00'));
        $this->assertStringContainsString('Через 24 год: Плановий ремонт', $this->texts()[1]);
        $this->assertSame(2, $remind('2026-11-26 09:30'));

        $this->rows[1]['Час початку'] = '10:00';
        $this->poll();
        $this->assertStringContainsString('з 09:00 до 17:00', $this->texts()[2]);
        $this->assertStringContainsString('з 10:00 до 17:00', $this->texts()[2]);
        $this->assertSame(Delivery::EXPIRED, Delivery::query()->where('dedupe_key', 'like', 'remind:%')->sole()->status);
        $this->assertSame(4, $remind('2026-11-26 10:00'));
        $this->assertSame(4, $remind('2026-11-27 09:29'));
        $this->assertSame(5, $remind('2026-11-27 09:30'));
        $this->assertStringContainsString('Через 30 хв', $this->texts()[4]);
        $this->assertSame(5, $remind('2026-11-27 09:45'));
    }

    public function test_no_reminders_when_off_or_late_and_cancelled_is_not_ended(): void
    {
        $this->chat->update(['remind_minutes' => null]);
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '24', 'Планова', '26.11.2026 09:00', '26.11.2026 17:00');
        $this->poll();
        $this->travelTo(CarbonImmutable::parse('2026-11-26 08:45', 'Europe/Kyiv'));
        app(AddressReminders::class)->queueDue();
        $this->assertCount(1, $this->texts());

        // Published less than a day before: only the second reminder.
        $this->chat->update(['remind_minutes' => 30]);
        app(AddressReminders::class)->queueDue();
        $this->assertCount(2, $this->texts());
        array_pop($this->rows);
        $this->poll();
        $this->poll();
        $this->assertStringContainsString('Скасовано', $this->texts()[2]);
    }

    public function test_planned_works_long_over_leave_the_list_silently(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '24', 'Планова', '25.11.2026 13:00', '25.11.2026 17:00');
        $this->poll();
        $this->travelTo(CarbonImmutable::parse('2026-11-26 00:10', 'Europe/Kyiv'));
        array_pop($this->rows);
        $this->poll();
        $this->poll();
        $this->assertCount(1, $this->texts());
        $this->assertNotNull(AddressEvent::query()->sole()->ended_at);
    }

    public function test_end_of_one_outage_mentions_another(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '24', 'Аварійне відключення', '25.11.2026 11:50');
        $this->row('пр. Соборний', '24', 'Термінова', '25.11.2026 10:00', '25.11.2026 17:00');
        $this->poll();
        unset($this->rows[1]);
        $this->rows = array_values($this->rows);
        $this->poll();
        $this->poll();
        $this->assertStringContainsString('діє інше відключення', $this->texts()[2]);
    }

    public function test_inactive_chat_gets_nothing(): void
    {
        $this->chat->update(['active' => false]);
        $this->follow('пр. Соборний', '24');
        $this->row('пр. Соборний', '24', 'Аварійне відключення', '25.11.2026 11:50');
        $this->poll();
        $this->assertCount(0, $this->texts());
    }

    public function test_address_is_added_without_a_queue_and_shows_current_outages(): void
    {
        $this->row('пр. Соборний', '150', 'Аварійне відключення', '25.11.2026 11:50');
        $this->poll();
        $this->assertStringContainsString('Черга для цього не потрібна', $this->say(Keyboards::ADDRESS));
        $this->press('a+');
        $text = $this->say('Запоріжжя, Соборный 150');
        $this->assertStringContainsString('пр. Соборний, 150', $text);
        $this->assertStringContainsString('з 11:50', $text);
        $this->assertStringNotContainsString('немає у списку обленерго', $text);
        $this->poll();
        $this->assertCount(0, $this->texts());
        $this->assertNull($this->chat->refresh()->address_draft);
        $this->assertStringContainsString('Моя адреса', $this->say('привіт'));

        $this->press('a-:'.Address::query()->sole()->id);
        $this->assertSame(0, AddressEvent::query()->count());
    }

    public function test_former_names_choice_of_streets_and_unknown_houses(): void
    {
        $this->press('a+');
        $this->say('Малиновського 99');
        $address = Address::query()->sole();
        $this->assertSame('вул. Європейська (Малиновського)', $address->street->name);
        $this->assertFalse($address->house_known);
        $this->assertStringContainsString('немає у списку обленерго', $this->say(Keyboards::ADDRESS));

        $this->press('a+');
        $this->assertStringContainsString('Оберіть вулицю', $this->say('лісова'));
        $lisova = Street::query()->where('name', 'вул. Лісова')->sole();
        $this->assertStringContainsString('номер будинку', $this->press("as:{$lisova->id}"));
        $this->say('буд. 14 А');
        $this->assertSame('14а', Address::query()->latest('id')->first()->house_key);

        $this->press('a+');
        $this->say('8 Березня');
        $this->say('12');
        $this->assertSame(3, Address::query()->count());
        config(['addresses.max_addresses' => 3]);
        $this->press('a+');
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'до 3 адрес') && ($r['show_alert'] ?? false));
        $this->assertNull($this->chat->refresh()->address_draft);
    }

    public function test_unknown_street_typo_and_abandoned_draft(): void
    {
        $this->press('a+');
        $this->assertStringContainsString('не знайдено', $this->say('Хрещатик 1'));
        $this->assertStringContainsString('Оберіть вулицю', $this->say('Собрний 150'));
        $this->say(Keyboards::GUIDE);
        $this->assertNull($this->chat->refresh()->address_draft);

        $this->press('a+');
        $this->travel(20)->minutes();
        $this->say('Соборний 150');
        $this->assertSame(0, Address::query()->count());
    }

    public function test_foreign_address_is_not_removed(): void
    {
        $other = Chat::query()->create(['telegram_id' => 6, 'type' => 'private']);
        $address = $this->follow('пр. Соборний', '24', $other);
        $this->press("a-:{$address->id}");
        $this->assertModelExists($address);
    }

    public function test_second_city_is_asked_for(): void
    {
        config(['addresses.cities.other' => ['name' => 'Інше', ...array_diff_key(config('addresses.cities.zaporizhzhia'), ['name' => 1])]]);
        app(CityRegistry::class)->cities();
        $this->assertStringContainsString('Оберіть місто', $this->press('a+'));
        $this->assertStringContainsString('вулицю та будинок', $this->press('a+:zaporizhzhia'));
        $this->assertStringContainsString('Запоріжжя, пр. Соборний, 150', $this->say('Соборний 150'));
    }

    public function test_streets_that_disappear_are_reported_if_used(): void
    {
        Street::query()->create(['city_id' => City::query()->sole()->id, 'name' => 'вул. Стара', 'key' => 'вул. стара', 'active' => true]);
        $this->follow('вул. Стара', '1');
        $this->travel(1)->days();
        $this->poll();
        $this->assertFalse(Street::query()->where('key', 'вул. стара')->sole()->active);
        $this->assertStringContainsString('вул. Стара', $this->texts()[0]);
    }
}
