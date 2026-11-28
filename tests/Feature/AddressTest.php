<?php

namespace Tests\Feature;

use App\Address\AddressReminders;
use App\Address\AddressText;
use App\Address\CityPoller;
use App\Address\HouseDirectory;
use App\Models\Address;
use App\Models\AddressEvent;
use App\Models\ChannelPost;
use App\Models\Chat;
use App\Models\City;
use App\Models\CityEvent;
use App\Models\Delivery;
use App\Models\Street;
use App\Monitor\Checks;
use App\Telegram\DeliveryChannel;
use App\Telegram\Keyboards;
use App\Telegram\UpdateHandler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    private const STREETS = ['пр. Соборний', 'вул. Європейська (Малиновського)', 'вул. Лісова', 'пров. Лісова', 'вул. 8 Березня', 'вул. Петра Болбочана (Інтернаціональна)'];

    private const ACTUAL = 'У зв’язку з проведенням термінових ремонтних робіт сьогодні о 12-00 знеструмлено низку споживачів у м. Запоріжжя.';

    private const ANNOUNCED = 'У зв’язку з проведенням планових ремонтних робіт 27 листопада буде знеструмлено низку споживачів у м. Запоріжжя.';

    private const EXTENDED = 'Шановні споживачі! Увага!  Зміна орієнтовного часу відновлення електропостачання. Аварійні роботи продовжено до 17-00  26.11.2026';

    private const PARTIAL = "Шановні споживачі! Увага! Електропостачання відновлено частково. \nАдреси  споживачів, які залишаються знеструмленими вказані в доданому файлі";

    /** @var array<int, array{time: CarbonImmutable, text: string, reply: ?int, file: ?string}> */
    private array $posts = [];

    /** @var array<int, string> content by post */
    private array $files = [];

    private bool $downloads = true;

    private string $downloadError = 'wrong type';

    /** @var array<int, string> the file that Telegram keeps for a link, by post; with "?single" too when it is "always" */
    private array $staleFiles = [];

    private bool $alwaysStale = false;

    private int $status = 200;

    private Chat $chat;

    private int $nextId = 60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00', 'Europe/Kyiv'));
        Http::fake(fn ($request) => $this->respond($request));
        $this->chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private', 'active' => true, 'remind_minutes' => 30, 'perks_at' => now(), 'keyboard_version' => Keyboards::version(5, true)]);
        $this->chat->subscriptions()->create(['queue' => '1.1']);
        $this->publish('ГПВ');
        $this->poll();
    }

    private function respond($request)
    {
        $url = $request->url();

        if (str_contains($url, 'script.google.com')) {
            $result = json_decode($request['request'])[0] === 'getStreetList' ? self::STREETS : ['150', '152а', '10-20'];

            return Http::response(")]}'\n\n".json_encode([['op.exec', [0, json_encode($result)]], ['di', 5]]));
        }
        if (str_contains($url, '/s/zoe_alarm')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return Http::response($this->page($query['before'] ?? null), $this->status);
        }
        if (str_ends_with($url, 'sendDocument')) {
            $id = (int) basename($request['document']);
            $stale = ($this->alwaysStale || ! str_contains($request['document'], '?')) ? ($this->staleFiles[$id] ?? null) : null;

            return $this->downloads
                ? Http::response(['ok' => true, 'result' => ['message_id' => 900 + $id, 'document' => ['file_id' => (string) $id, 'file_name' => $stale ?? $this->posts[$id]['file'], 'file_size' => 100]]])
                : Http::response(['ok' => false, 'error_code' => 400, 'description' => $this->downloadError], 400);
        }
        if (str_ends_with($url, 'getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_path' => "documents/{$request['file_id']}"]]);
        }
        if (str_contains($url, '/file/bot')) {
            return Http::response($this->files[(int) basename($url)]);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => str_ends_with($url, 'sendMessage') ? $this->nextId++ : 60]]);
    }

    private function page(?string $before): string
    {
        $ids = array_filter(array_keys($this->posts), fn (int $id) => $before === null || $id < (int) $before);
        rsort($ids);
        $html = '';

        foreach (array_slice($ids, 0, 20) as $id) {
            $post = $this->posts[$id];
            $html = '<div class="tgme_widget_message" data-post="zoe_alarm/'.$id.'">'
                .($post['reply'] ? '<a class="tgme_widget_message_reply" href="https://t.me/zoe_alarm/'.$post['reply'].'"><div class="js-message_reply_text">x</div></a>' : '')
                .($post['file'] ? '<a class="tgme_widget_message_document_wrap"><div class="tgme_widget_message_document_title">'.$post['file'].'</div></a>' : '')
                .'<div class="js-message_text">'.nl2br(e($post['text'])).'</div>'
                .'<a class="tgme_widget_message_date"><time datetime="'.$post['time']->toIso8601String().'"></time></a></div>'.$html;
        }

        return "<html><body>{$html}</body></html>";
    }

    private function publish(string $text, ?int $reply = null, ?string $file = null, array $rows = []): int
    {
        $id = $this->posts === [] ? 100 : max(array_keys($this->posts)) + 1;
        $this->posts[$id] = ['time' => CarbonImmutable::now(), 'text' => $text, 'reply' => $reply, 'file' => $file];
        $this->files[$id] = $this->table($rows);

        return $id;
    }

    /** Rows: street, houses, reason, start date, start time, end date, end time; empty values are as above. */
    private function table(array $rows): string
    {
        $rows = [['', '', 'ЗМЕМ'], ['№ з/п', 'Населений пункт', 'Вулиця', 'Буд', 'Причина', 'Початок перерви електропостачання', '', 'Орієнтовне відновлення електропостачання'],
            ['', '', '', '', '', 'Дата', 'Час', 'Дата ', 'Час'], ...array_map(fn (array $row) => ['', 'м. Запоріжжя', ...$row], $rows)];
        $xml = '';

        foreach ($rows as $n => $row) {
            $xml .= '<row r="'.($n + 1).'">';
            foreach ($row as $c => $value) {
                $xml .= $value === '' ? '' : '<c r="'.chr(65 + $c).($n + 1).'" t="inlineStr"><is><t>'.e($value).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        $path = tempnam(sys_get_temp_dir(), 'x');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function emergency(array $houses = ['22-28'], string $file = 'ЗМЕМ_ПСР_7_25_11_26.xlsx'): int
    {
        $rows = array_map(fn (string $list) => ['пр. Соборний', $list, '', '', '', '', ''], $houses);
        $rows[0] = ['пр. Соборний', $houses[0], 'аварійна', '25.11.26', '11-50', '25.11.26', '21-00'];

        return $this->publish('Через пошкодження електрообладнання сьогодні знеструмлено низку споживачів у м. Запоріжжя', null, $file, $rows);
    }

    private function planned(string $start = '09-00', string $date = '27.11.26', string $houses = '24', string $text = self::ANNOUNCED): int
    {
        return $this->publish($text, null, 'ЗМЕМ_ЦР_1_27_11_26.xlsx', [['пр. Соборний', $houses, 'Планова', $date, $start, $date, '17-00']]);
    }

    private function poll(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->travel(1)->minutes();
            app(CityPoller::class)->pollDue();
        }
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

    private function last(): string
    {
        return (string) Delivery::query()->latest('id')->value('text');
    }

    private function remind(string $time): int
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Europe/Kyiv'));
        app(AddressReminders::class)->queueDue();

        return count($this->texts());
    }

    private function say(string $text): string
    {
        return $this->update(['message' => ['message_id' => 50, 'text' => $text, 'chat' => ['id' => 5, 'type' => 'private']]]);
    }

    private function press(string $data, int $messageId = 1): string
    {
        return $this->update(['callback_query' => ['id' => 'x', 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => 5, 'type' => 'private']]]]);
    }

    private function assertDeleted(int $id, bool $deleted = true): void
    {
        $check = fn ($r) => str_ends_with($r->url(), 'deleteMessages') && in_array($id, $r['message_ids'], true);
        $deleted ? Http::assertSent($check) : Http::assertNotSent($check);
    }

    private function update(array $update): string
    {
        $this->travel(4)->seconds();
        app(UpdateHandler::class)->handle($update);

        return (string) Http::recorded()->map(fn ($pair) => $pair[0])->filter(fn ($r) => isset($r['text'], $r['chat_id']))->last()?->data()['text'];
    }

    public function test_emergency_is_reported_extended_and_restored_by_a_chain_of_replies(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->emergency();
        $this->poll();
        $this->assertStringContainsString('Аварійне відключення', $this->last());
        $this->assertStringContainsString('з 11:50, орієнтовно до 21:00', $this->last());
        $this->assertSame(1, CityEvent::query()->sole()->rows()->count());
        // The file came through the admin chat, which is left clean.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'sendDocument') && $r['chat_id'] === 999 && $r['disable_notification']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'deleteMessage') && $r['message_id'] === 900 + $id);

        $reply = $this->publish(self::EXTENDED, $id);
        $this->poll();
        $this->assertStringContainsString('Змінено час', $this->last());
        $this->assertStringContainsString('до 17:00 (26 листопада)', $this->last());

        $this->publish('Електропостачання відновлено.', $reply);
        $this->poll();
        $this->assertStringContainsString('Завершено', $this->last());
        $this->assertNotNull(CityEvent::query()->sole()->closed_at);
        $this->poll(3);
        $this->assertCount(3, $this->texts());
    }

    public function test_extension_with_a_date_in_brackets_is_read(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->emergency();
        $this->poll();
        $reply = $this->publish('Шановні споживачі! Увага! Зміна орієнтовного часу відновлення електропостачання. Аварійні роботи продовжено до 16-00  (26.11.2026).', $id);
        $this->poll();
        $this->assertStringContainsString('до 16:00 (26 листопада)', $this->last());
        $this->publish('Шановні споживачі! Увага! Зміна орієнтовного часу відновлення електропостачання. Аварійні роботи продовжено до 22:00 (26.11.2026).', $reply);
        $this->poll();
        $this->assertStringContainsString('до 22:00 (26 листопада)', $this->last());
    }

    public function test_partial_restoration_is_told_to_each_house(): void
    {
        $this->follow('пр. Соборний', '24');
        $other = Chat::query()->create(['telegram_id' => 6, 'type' => 'private', 'perks_at' => now()]);
        $other->subscriptions()->create(['queue' => '1.1']);
        $this->follow('пр. Соборний', '25', $other);
        $id = $this->emergency(['22-28', '25']);
        $this->poll();
        $this->assertCount(2, $this->texts());
        $this->publish(self::PARTIAL, $id, 'ЗМЕМ_ПБ_1_.xlsx', [['пр. Соборний', '25', 'аварійна', '25.11.26', '11-50', '26.11.26', '09-00']]);
        $this->poll();
        $text = fn (int $chat) => Delivery::query()->where('telegram_id', $chat)->latest('id')->value('text');
        $this->assertStringContainsString('Завершено', $text(5));
        $this->assertStringContainsString('Змінено час', $text(6));
        $this->assertCount(4, $this->texts());
    }

    public function test_file_of_an_extension_is_believed_and_a_new_kind_is_told(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->publish(self::ACTUAL, null, 'ЗМЕМ_ПСР_5_25_11_26.xlsx', [['пр. Соборний', '22-28', 'термінові роботи', '25.11.26', '12-00', '25.11.26', '17-00']]);
        $this->poll();
        $this->assertStringContainsString('Терміновий ремонт', $this->last());
        $extended = 'Шановні споживачі! Увага!  Зміна орієнтовного часу відновлення електропостачання. Аварійні роботи продовжено до 12-00  26.11.2026';

        // The text and the file agree: only users hear of it.
        $reply = $this->publish($extended, $id, 'ЗМЕМ_ПСР_8_25_11_26.xlsx', [['пр. Соборний', '22-28', 'термінові роботи', '25.11.26', '12-00', '26.11.26', '12-00']]);
        $this->poll();
        $this->assertStringContainsString('Змінено час: Терміновий ремонт', $this->last());
        $this->assertStringContainsString('до 12:00 (26 листопада)', $this->last());
        $this->assertSame(ChannelPost::DONE, ChannelPost::query()->where('message_id', $reply)->value('status'));

        // The file says another time and kind: it is believed, the admin is warned.
        $this->publish($extended, $reply, 'ЗМЕМ_ПСР_8_26_11_26.xlsx', [['пр. Соборний', '22-28', 'аварійна', '25.11.26', '12-00', '26.11.26', '15-00']]);
        $this->poll();
        $user = (string) Delivery::query()->where('telegram_id', 5)->latest('id')->value('text');
        $this->assertStringContainsString('Змінено: Аварійне відключення', $user);
        $this->assertStringContainsString('Було: Терміновий ремонт', $user);
        $this->assertStringContainsString('до 15:00 (26 листопада)', $user);
        $admin = (string) Delivery::query()->where('telegram_id', 999)->latest('id')->value('text');
        $this->assertStringContainsString('текст: 26.11.2026 12:00, файл: 26.11.2026 15:00', $admin);
        $this->assertFalse(Delivery::query()->where('text', 'like', '%Незрозуміла%')->exists());
    }

    public function test_announcement_start_and_reminders(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->planned();
        $this->poll();
        $this->assertStringContainsString('🗓 <b>Плановий ремонт', $this->last());
        $this->assertSame(1, $this->remind('2026-11-26 08:59'));
        $this->assertSame(2, $this->remind('2026-11-26 09:00'));
        $this->assertStringContainsString('Через 24 год: Плановий ремонт', $this->last());
        $this->assertSame(3, $this->remind('2026-11-27 08:30'));
        $this->assertStringContainsString('Через 30 хв', $this->last());
        $this->assertSame(3, $this->remind('2026-11-27 09:30'));
        $this->assertStringContainsString('підтвердження немає', $this->say(Keyboards::ADDRESS));

        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:36', 'Europe/Kyiv'));
        $this->planned('10-35', text: self::ACTUAL);
        $this->poll();
        $this->assertStringContainsString('Почалося: Плановий ремонт', $this->last());
        $this->assertStringContainsString('з 10:35', $this->last());
        $this->assertSame(1, CityEvent::query()->count());
        $this->assertCount(4, $this->texts());
    }

    public function test_moved_announcement_is_reminded_again_and_a_removed_house_is_cancelled(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->planned();
        $this->poll();
        $this->assertSame(2, $this->remind('2026-11-26 09:00'));
        $this->planned('10-00');
        $this->poll();
        $this->assertStringContainsString('Було: 27 листопада, з 09:00', $this->last());
        $this->assertSame(Delivery::EXPIRED, Delivery::query()->where('dedupe_key', 'like', 'address:remind:%')->sole()->status);
        $this->assertSame(4, $this->remind('2026-11-26 10:00'));
        $this->planned('10-00', houses: '99');
        $this->poll();
        $this->assertStringContainsString('Скасовано', $this->last());
    }

    public function test_one_outage_at_several_addresses_is_one_message_and_time_is_carried_down(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->follow('вул. Петра Болбочана (Інтернаціональна)', '5');
        // Another order of words in the name.
        $this->publish(self::ACTUAL, null, 'ЗМЕМ_1.xlsx', [['пр. Соборний', '24', 'Термінова', '25.11.26', '11-58', '25.11.2026', '17:00'], ['вул. Болбочана Петра (Інтернаціональна)', '5 (2 пов)']]);
        $this->poll();
        $this->assertCount(1, $this->texts());
        $this->assertStringContainsString('Соборний, 24', $this->last());
        $this->assertStringContainsString(', 5 (2 пов)', $this->last());
        $this->assertSame(count(self::STREETS), Street::query()->count());
    }

    public function test_unknown_street_is_added_and_survives_the_directory(): void
    {
        $this->publish(self::ACTUAL, null, 'ЗМЕМ_1.xlsx', [['о. Хортиця', '52', 'Термінова', '25.11.26', '11-58', '25.11.26', '17-00']]);
        $this->poll();
        $this->assertFalse(Street::query()->where('key', 'о. хортиця')->sole()->listed);
        $this->travel(1)->days();
        $this->poll();
        $this->assertTrue(Street::query()->where('key', 'о. хортиця')->sole()->active);
    }

    public function test_numbers_stored_by_excel_are_read(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->publish(self::ACTUAL, null, 'ЗМЕМ_1.xlsx', [['пр. Соборний', '24', 'Термінова', '46351', '0.5', '46351', '0.70833333333333337']]);
        $this->poll();
        $this->assertStringContainsString('25 листопада, з 12:00, орієнтовно до 17:00', $this->last());
    }

    public function test_other_files_and_posts_are_ignored(): void
    {
        $id = $this->publish('x', null, 'Перерви_ЗапРЕМ_1.xlsx', []);
        $this->publish('Електропостачання відновлено', $id);
        $this->publish('просто текст');
        $this->poll();
        $this->assertSame(0, CityEvent::query()->count());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'sendDocument'));
    }

    public function test_failed_file_is_retried_then_reported_and_its_reply_waits(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->downloads = false;
        $id = $this->emergency();
        $reply = $this->publish(self::EXTENDED, $id);
        $this->poll(3);
        $this->assertCount(0, $this->texts());
        $this->downloads = true;
        $this->poll(6);
        $this->assertCount(2, $this->texts());
        $this->assertSame(ChannelPost::DONE, ChannelPost::query()->where('message_id', $reply)->sole()->status);

        $this->downloads = false;
        $this->emergency(file: 'ЗМЕМ_2.xlsx');
        $this->poll(70);
        $this->assertStringContainsString('людей за ним не сповіщено', $this->last());
        $this->assertSame(999, Delivery::query()->latest('id')->value('telegram_id'));
    }

    public function test_deleted_post_is_skipped_without_an_alarm(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->downloads = false;
        $this->downloadError = 'Bad Request: failed to get HTTP URL content';
        $deleted = $this->emergency();
        unset($this->posts[$deleted]);
        $this->publish('x');
        // Still in the channel: it is retried.
        $kept = $this->emergency(file: 'ЗМЕМ_2.xlsx');
        $this->poll();
        $this->assertSame(ChannelPost::SKIPPED, ChannelPost::query()->where('message_id', $deleted)->sole()->status);
        $this->assertSame(ChannelPost::PENDING, ChannelPost::query()->where('message_id', $kept)->sole()->status);
        $this->assertSame(1, ChannelPost::query()->where('message_id', $kept)->sole()->attempts);
        $this->assertCount(0, $this->texts());
    }

    public function test_broken_table_does_not_hold_up_others(): void
    {
        $this->follow('пр. Соборний', '24');
        $broken = $this->emergency(file: 'ЗМЕМ_2.xlsx');
        $this->files[$broken] = 'not a table';
        $this->emergency();
        $this->poll();
        $this->assertCount(1, $this->texts());
    }

    public function test_edited_file_updates_the_outage(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->emergency();
        $this->poll();
        $this->files[$id] = $this->table([['пр. Соборний', '24', 'аварійна', '25.11.26', '11-50', '25.11.26', '22-00']]);
        $this->posts[$id]['text'] .= ' (уточнено)';
        $this->poll();
        $this->assertStringContainsString('до 22:00', $this->last());
        $this->assertCount(2, $this->texts());
    }

    public function test_replaced_file_is_another_outage_and_the_old_one_is_kept(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->follow('пр. Соборний', '40');
        $first = $this->emergency(['22-28'], 'ЗМЕМ_ПСР_5.xlsx');
        // Posted again with the same file by mistake.
        $wrong = $this->emergency(['22-28'], 'ЗМЕМ_ПСР_5.xlsx');
        $this->poll();
        $this->assertSame(1, CityEvent::query()->count());

        $this->posts[$wrong]['file'] = 'ЗМЕМ_ПСР_8.xlsx';
        $this->files[$wrong] = $this->table([['пр. Соборний', '40', 'аварійна', '25.11.26', '11-50', '25.11.26', '21-00']]);
        $this->staleFiles[$wrong] = 'ЗМЕМ_ПСР_5.xlsx';
        $this->poll();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'sendDocument') && $r['document'] === "https://t.me/zoe_alarm/{$wrong}?single");
        $this->assertSame(2, CityEvent::query()->count());
        $this->assertSame('22-28', ChannelPost::query()->where('message_id', $first)->sole()->event->rows()->sole()->houses);
        $this->assertSame('40', ChannelPost::query()->where('message_id', $wrong)->sole()->event->rows()->sole()->houses);
    }

    public function test_old_file_given_again_is_reported_at_once(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->emergency();
        $this->poll();
        $this->posts[$id]['file'] = 'ЗМЕМ_ПСР_8.xlsx';
        $this->staleFiles[$id] = 'ЗМЕМ_ПСР_7_25_11_26.xlsx';
        $this->alwaysStale = true;
        $this->poll();
        $this->assertSame(ChannelPost::FAILED, ChannelPost::query()->where('message_id', $id)->sole()->status);
        $this->assertStringContainsString('Telegram віддає старий', $this->last());
        $this->assertSame(999, Delivery::query()->latest('id')->value('telegram_id'));
    }

    public function test_unknown_reply_is_reported_to_the_admin_only(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->emergency();
        $this->poll();
        $this->publish('Електропостачання не відновлено', $id);
        $this->poll();
        $this->assertStringContainsString('Незрозуміла відповідь', $this->last());
        $this->assertSame(999, Delivery::query()->latest('id')->value('telegram_id'));
        $this->assertNull(CityEvent::query()->sole()->closed_at);
    }

    public function test_history_is_silent_and_closed_outages_are_not_downloaded(): void
    {
        ChannelPost::query()->delete();
        $this->follow('пр. Соборний', '24');
        // The announcement, the actual start and the restoration.
        $this->planned('13-00', '25.11.26');
        $actual = $this->planned('13-30', '25.11.26', text: self::ACTUAL);
        $this->publish('Електропостачання відновлено', $actual);
        $this->emergency();
        $this->poll();
        $this->assertCount(0, $this->texts());
        $this->assertSame(1, CityEvent::query()->whereNull('closed_at')->count());
        $this->assertStringContainsString('з 11:50', $this->say(Keyboards::ADDRESS));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'sendDocument') && basename($r['document']) === (string) $actual);
    }

    public function test_reply_to_an_old_post_reads_it(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->posts[40] = ['time' => CarbonImmutable::parse('2026-11-10 12:00'), 'text' => 'Через пошкодження', 'reply' => null, 'file' => 'ЗМЕМ_старий.xlsx'];
        $this->files[40] = $this->table([['пр. Соборний', '24', 'аварійна', '10.11.26', '11-50', '10.11.26', '21-00']]);
        for ($i = 0; $i < 25; $i++) {
            $this->publish('x');
        }
        $this->publish(self::EXTENDED, 40);
        $this->poll();
        $this->assertStringContainsString('до 17:00 (26 листопада)', $this->last());
    }

    public function test_end_of_one_outage_mentions_another_and_old_ones_are_forgotten(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->emergency();
        $this->emergency(file: 'ЗМЕМ_2.xlsx');
        $this->poll();
        $this->publish('Електропостачання відновлено', $id);
        $this->poll();
        $this->assertStringContainsString('діє інше відключення', $this->last());
        $count = count($this->texts());
        $this->travel(4)->days();
        $this->poll();
        $this->assertSame(0, CityEvent::query()->whereNull('closed_at')->count());
        $this->assertSame(0, AddressEvent::query()->whereNull('ended_at')->count());
        $this->assertCount($count, $this->texts());
    }

    public function test_late_news_about_a_beginning_is_not_sent(): void
    {
        $this->follow('пр. Соборний', '24');
        $id = $this->emergency();
        $this->travel(4)->hours();
        $this->poll();
        $this->assertCount(0, $this->texts());
        $this->publish('Електропостачання відновлено', $id);
        $this->poll();
        $this->assertStringContainsString('Завершено', $this->last());
    }

    public function test_nothing_is_sent_without_a_queue_or_to_those_who_left(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->planned();
        $this->poll();
        $this->chat->subscriptions()->delete();
        $this->assertSame(1, $this->remind('2026-11-26 09:00'));
        $id = $this->emergency();
        $this->poll();
        $this->assertCount(1, $this->texts());

        $this->chat->subscriptions()->create(['queue' => '1.1']);
        $this->assertStringContainsString('з 11:50', $this->say(Keyboards::ADDRESS));
        $this->chat->update(['active' => false]);
        $this->publish('Електропостачання відновлено', $id);
        $this->poll();
        $this->assertCount(1, $this->texts());
    }

    public function test_without_a_queue_only_the_selection_and_the_guide_are_available(): void
    {
        $this->chat->subscriptions()->delete();
        foreach ([Keyboards::SCHEDULE, Keyboards::SETTINGS, Keyboards::ADDRESS, Keyboards::INVITE, 'привіт'] as $text) {
            $this->assertStringContainsString('Оберіть чергу', $this->say($text), $text);
        }
        $this->assertStringContainsString('Довідник', $this->say(Keyboards::GUIDE));
        foreach (['mute', 'r:10', 'a+', 'address'] as $data) {
            $this->assertStringContainsString('Оберіть чергу', $this->press($data), $data);
        }
        $this->assertFalse($this->chat->refresh()->silent);
        $this->assertNull($this->chat->address_draft);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Спочатку оберіть') && ($r['show_alert'] ?? false));
        $this->press('q+:1.1');
        $this->press('mute');
        $this->assertTrue($this->chat->refresh()->silent);
    }

    public function test_address_is_added_with_a_notice_and_shows_current_outages(): void
    {
        $this->emergency(['150']);
        $this->poll();
        $this->assertStringContainsString('Експериментальна функція', $this->say(Keyboards::ADDRESS));
        $this->assertStringContainsString('Експериментальна функція', $this->press('a+'));
        $text = $this->say('Запоріжжя, Соборный 150');
        $this->assertStringContainsString('пр. Соборний, 150', $text);
        $this->assertStringContainsString('з 11:50', $text);
        $this->assertStringNotContainsString('немає у списку обленерго', $text);
        $this->poll();
        $this->assertCount(0, $this->texts());
        $this->assertNull($this->chat->refresh()->address_draft);

        $this->press('a-:'.Address::query()->sole()->id);
        $this->assertSame(0, AddressEvent::query()->count());
    }

    public function test_former_names_choice_of_streets_and_unknown_houses(): void
    {
        $this->press('a+');
        $this->say('Малиновського 99');
        $address = Address::query()->sole();
        $this->assertSame('вул. Європейська (Малиновського)', $address->street->name);
        Http::assertNotSent(fn ($r) => str_contains($r->body(), 'getBuildingsForStreet'));
        $this->assertNull($address->house_known);
        app(HouseDirectory::class)->checkDue();
        $this->assertFalse($address->refresh()->house_known);
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
        $address = $this->follow('пр. Соборний', '24', Chat::query()->create(['telegram_id' => 6, 'type' => 'private']));
        $this->press("a-:{$address->id}");
        $this->assertModelExists($address);
    }

    public function test_second_city_is_asked_for(): void
    {
        config(['addresses.cities.other' => ['name' => 'Інше', ...array_diff_key(config('addresses.cities.zaporizhzhia'), ['name' => 1])]]);
        $this->assertStringContainsString('Оберіть місто', $this->press('a+'));
        $this->assertStringContainsString('вулицю та будинок', $this->press('a+:zaporizhzhia'));
        $this->assertStringContainsString('Запоріжжя, пр. Соборний, 150', $this->say('Соборний 150'));
    }

    public function test_unavailable_channel_and_lost_streets_are_reported(): void
    {
        Street::query()->create(['city_id' => City::query()->sole()->id, 'name' => 'вул. Стара', 'key' => 'вул. стара', 'active' => true]);
        $this->follow('вул. Стара', '1');
        $this->travel(1)->days();
        $this->status = 500;
        $this->poll();
        $this->assertStringContainsString('вул. Стара', $this->last());
        $this->assertSame(1, City::query()->sole()->failures);
        $this->assertContains('city:zaporizhzhia', array_column(app(Checks::class)->run(), 'key'));
    }

    public function test_schedules_and_addresses_are_sent_separately(): void
    {
        foreach (['schedule:1', 'remind:1', 'admin:1', 'address:new:1:1', 'address:remind:reminded_at:1:1'] as $key) {
            Delivery::query()->create(['telegram_id' => 5, 'dedupe_key' => $key, 'text' => $key, 'available_at' => now()]);
        }
        $keys = fn (DeliveryChannel $channel) => $channel->pending()->pluck('text')->sort()->values()->all();
        $this->assertSame(['schedule:1'], $keys(DeliveryChannel::Broadcast));
        $this->assertSame(['admin:1', 'remind:1'], $keys(DeliveryChannel::Urgent));
        $this->assertSame(['address:new:1:1', 'address:remind:reminded_at:1:1'], $keys(DeliveryChannel::Address));
    }

    public function test_schedule_processes_do_not_touch_addresses(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->emergency();
        $this->travel(1)->minutes();
        $this->artisan('app:worker', ['--once' => true])->assertSuccessful();
        $this->artisan('app:reminders', ['--once' => true])->assertSuccessful();
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'sendDocument'));
        $this->assertSame(0, CityEvent::query()->count());

        $this->artisan('app:addresses', ['--once' => true])->assertSuccessful();
        $this->assertSame(Delivery::SENT, Delivery::query()->where('dedupe_key', 'like', 'address:%')->sole()->status);
    }

    public function test_list_of_addresses_stays_while_there_is_one(): void
    {
        $this->say(Keyboards::ADDRESS); // 60, empty
        $this->say(Keyboards::SCHEDULE); // 61
        $this->assertDeleted(60);

        $this->say(Keyboards::ADDRESS); // 62
        $this->press('a+', 62);
        $this->say('Соборний 150'); // 63, the list
        $this->assertNull($this->chat->refresh()->menu_message_id);
        $this->say(Keyboards::SCHEDULE); // 64
        $this->say(Keyboards::SETTINGS); // 65
        $this->assertDeleted(63, false);

        // The draft does not take the place of a kept list.
        $this->press('a+', 63); // 66
        $this->assertSame(66, $this->chat->refresh()->menu_message_id);
        $this->assertDeleted(65);
        $this->say(Keyboards::SCHEDULE); // 67
        $this->assertDeleted(66);
        $this->assertDeleted(63, false);

        // Without addresses the list is a service screen again.
        $this->press('a-:'.Address::query()->sole()->id, 63);
        $this->assertSame(63, $this->chat->refresh()->menu_message_id);
        $this->say(Keyboards::GUIDE); // 68
        $this->assertDeleted(63);
    }

    public function test_only_the_first_users_get_perks_and_keep_them(): void
    {
        config(['perks.limit' => 2]);
        $this->press('q+:2.1');
        $this->assertTrue($this->chat->refresh()->perks_at->lessThan(now()->subSecond()));

        $join = fn (int $id) => $this->update(['callback_query' => ['id' => 'x', 'data' => 'q+:1.1', 'message' => ['message_id' => 1, 'chat' => ['id' => $id, 'type' => 'private']]]]);
        $join(6);
        $join(7);
        $this->assertNotNull(Chat::query()->where('telegram_id', 6)->sole()->perks_at);
        $late = Chat::query()->where('telegram_id', 7)->sole();
        $this->assertNull($late->perks_at);

        // Leaving and coming back keeps the perks.
        $member = fn (string $status) => ['my_chat_member' => ['chat' => ['id' => 6, 'type' => 'private'], 'new_chat_member' => ['status' => $status]]];
        $this->update($member('kicked'));
        $this->update($member('member'));
        $this->update(['message' => ['message_id' => 50, 'text' => '/start', 'chat' => ['id' => 6, 'type' => 'private']]]);
        $this->assertNotNull(Chat::query()->where('telegram_id', 6)->sole()->perks_at);
    }

    public function test_addresses_are_closed_without_perks(): void
    {
        $this->follow('пр. Соборний', '24');
        $this->chat->update(['perks_at' => null, 'keyboard_version' => Keyboards::version(5, false)]);

        $this->assertStringContainsString('Графік', $this->say(Keyboards::ADDRESS));
        $this->assertStringNotContainsString('Відключення за адресою', $this->say(Keyboards::GUIDE));
        foreach (['a+', 'a+:zaporizhzhia', 'as:1', 'address', 'address_draft'] as $data) {
            $this->assertStringNotContainsString('адрес', $this->press($data), $data);
        }
        $this->press('a-:'.Address::query()->sole()->id);
        $this->assertSame(1, Address::query()->count());
        $this->assertNull($this->chat->refresh()->address_draft);
        Http::assertNotSent(fn ($r) => in_array(Keyboards::ADDRESS, array_column(array_merge(...$r['reply_markup']['keyboard'] ?? []), 'text'), true));

        $this->emergency();
        $this->poll();
        $this->assertCount(0, $this->texts());
    }
}
