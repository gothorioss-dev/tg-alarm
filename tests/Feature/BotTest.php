<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Delivery;
use App\Telegram\DeliveryCleanup;
use App\Telegram\DeliverySender;
use App\Telegram\Keyboards;
use App\Telegram\Messages;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 60, 'username' => 'zoe_bot']]));
    }

    private function update(array $update): void
    {
        app(UpdateHandler::class)->handle($update);
    }

    private function message(string $text, string $type = 'private', int $id = 5, array $extra = []): array
    {
        return ['message' => ['message_id' => 50, 'text' => $text, 'chat' => ['id' => $id, 'type' => $type], 'from' => ['id' => 7], ...$extra]];
    }

    private function press(string $data, string $type = 'private', int $id = 5): array
    {
        return ['callback_query' => ['id' => 'x', 'data' => $data, 'from' => ['id' => 7], 'message' => ['message_id' => 1, 'chat' => ['id' => $id, 'type' => $type]]]];
    }

    public function test_user_selects_several_queues_and_stops(): void
    {
        $this->fake();
        $this->update($this->message('/start'));
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', '<a href="https://www.zoe.com.ua/outage/">'));
        foreach (['q:1.1', 'q:5.2', 'q:1.1', 'q:6.1', 'q:9.9'] as $data) {
            $this->update($this->press($data));
        }
        $chat = Chat::query()->sole();
        $this->assertSame(['5.2', '6.1'], $chat->subscriptions->pluck('queue')->all());

        $this->update($this->press('done'));
        $this->update($this->press('stop'));
        $this->assertTrue($chat->refresh()->active);
        $chat->update(['active' => false]);
        $this->update($this->message('/start'));
        $this->assertTrue($chat->refresh()->active);
    }

    private function hasButton($request, string $data): bool
    {
        return in_array($data, array_column(array_merge(...($request['reply_markup']['inline_keyboard'] ?? [[]])), 'callback_data'), true);
    }

    public function test_screens_are_edited_in_place_and_schedules_cannot_be_turned_off(): void
    {
        $this->fake();
        $this->update($this->message('/start'));
        $this->update($this->press('done'));
        Http::assertSent(fn ($r) => ($r['show_alert'] ?? false) === true);

        $this->update($this->press('q:5.2'));
        $chat = Chat::query()->sole();

        // Buttons of earlier messages only open the settings.
        foreach (['stop', 'enable'] as $data) {
            $this->update($this->press($data));
            $this->assertTrue($chat->refresh()->active);
        }

        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'editMessageText') && $this->hasButton($r, 'mute') && $this->hasButton($r, 'r:0'));
        Http::assertNotSent(fn ($r) => $this->hasButton($r, 'stop'));
    }

    public function test_repeated_tap_is_harmless(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), 'editMessage')
            ? Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified'], 400)
            : Http::response(['ok' => true, 'result' => true]));
        $this->update($this->press('q:1.1'));
        $this->update($this->press('done'));

        $this->assertSame(1, Chat::query()->sole()->subscriptions()->count());
    }

    public function test_groups_are_left_and_ignored(): void
    {
        $this->fake();
        $this->update($this->message('/start', 'supergroup', -5));
        $this->update($this->press('q:1.1', 'supergroup', -5));
        Http::assertNothingSent();

        $this->update(['my_chat_member' => ['chat' => ['id' => -5, 'type' => 'supergroup'], 'old_chat_member' => ['status' => 'left'], 'new_chat_member' => ['status' => 'member']]]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'leaveChat') && $r['chat_id'] === -5);
        $this->assertSame(0, Chat::query()->count());
    }

    public function test_guide_links_to_enabled_sources(): void
    {
        config(['sources.sources.telegram.priority' => 200]);
        $messages = app(Messages::class);

        foreach ([$messages->guide()] as $text) {
            $this->assertStringContainsString('<a href="https://www.zoe.com.ua/outage/">', $text);
            $this->assertStringContainsString('<a href="https://t.me/zoe_alarm">', $text);
            $this->assertStringNotContainsString('груп', $text);
            $this->assertLessThan(strpos($text, 'zoe.com.ua'), strpos($text, 't.me'));
        }

        config(['sources.sources.telegram.enabled' => false]);
        $this->assertStringNotContainsString('t.me', $messages->guide());
    }

    private function assertDeleted(int $id, bool $deleted = true): void
    {
        $check = fn ($r) => str_ends_with($r->url(), 'deleteMessage') && $r['message_id'] === $id;
        $deleted ? Http::assertSent($check) : Http::assertNotSent($check);
    }

    public function test_schedule_stays_and_service_screens_are_replaced(): void
    {
        $next = 60;
        Http::fake(function ($r) use (&$next) {
            return Http::response(['ok' => true, 'result' => str_ends_with($r->url(), 'sendMessage') ? ['message_id' => $next++] : true]);
        });
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private', 'menu_message_id' => 40, 'keyboard_version' => Keyboards::version(5)]);
        $chat->subscriptions()->create(['queue' => '1.1']);

        $this->update($this->message(Keyboards::SCHEDULE)); // 60
        $this->assertNull($chat->refresh()->menu_message_id);
        $this->update($this->message(Keyboards::SETTINGS)); // 61
        $this->update($this->message(Keyboards::QUEUES)); // 62

        $this->assertDeleted(40);
        $this->assertDeleted(50);
        $this->assertDeleted(60, false);
        $this->assertDeleted(61);

        // The selection screen becomes a schedule and is kept too.
        $query = $this->press('done');
        $query['callback_query']['message']['message_id'] = 62;
        $this->update($query);
        $this->update($this->message(Keyboards::GUIDE)); // 63
        $this->assertDeleted(62, false);
        $this->assertSame(63, $chat->refresh()->menu_message_id);
    }

    public function test_other_text_restores_the_bottom_keyboard_and_old_buttons_still_work(): void
    {
        $this->fake();
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private']);
        $chat->subscriptions()->create(['queue' => '1.1']);
        $this->update($this->message('привіт'));
        Http::assertSent(fn ($r) => ($r['reply_markup']['keyboard'][0][0]['text'] ?? '') === Keyboards::SCHEDULE);

        foreach (['schedule', 'menu', 'buttons', 'sources', 'remind', 'new:schedule'] as $data) {
            $this->update($this->press($data));
        }
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'editMessageText') && str_contains($r['text'], 'Довідник'));
    }

    public function test_every_bottom_button_opens_its_screen(): void
    {
        $this->fake();
        Chat::query()->create(['telegram_id' => 5, 'type' => 'private'])->subscriptions()->create(['queue' => '1.1']);

        foreach (array_merge(...Keyboards::bottom(5)['keyboard']) as $button) {
            $this->assertNotNull(Keyboards::screen($button['text'], 5));
            $this->assertNotNull(Keyboards::screen(str_replace("\u{FE0F}", '', $button['text']), 5));
            $this->update($this->message($button['text']));
        }

        Http::assertSent(fn ($r) => $this->hasButton($r, 'q:1.1'));
        Http::assertSent(fn ($r) => $this->hasButton($r, 'r:30'));
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Довідник'));
    }

    public function test_invite_shares_the_bot_link_with_a_ready_text(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 60, 'username' => 'zoe_bot']]));
        Chat::query()->create(['telegram_id' => 5, 'type' => 'private'])->subscriptions()->create(['queue' => '1.1']);
        $this->update($this->message(Keyboards::INVITE));

        Http::assertSent(function ($r) {
            $url = $r['reply_markup']['inline_keyboard'][0][0]['url'] ?? '';
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_starts_with($url, 'https://t.me/share/url?')
                && $query['url'] === 'https://t.me/zoe_bot'
                && $query['text'] === app(Messages::class)->inviteText();
        });
    }

    public function test_start_with_a_parameter_turns_notifications_back_on(): void
    {
        $this->fake();
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private', 'active' => false]);
        $chat->subscriptions()->create(['queue' => '1.1']);
        $this->update($this->message('/start abc'));

        $this->assertTrue($chat->refresh()->active);
    }

    public function test_descriptions_fit_telegram_limits(): void
    {
        $messages = app(Messages::class);
        $this->assertLessThanOrEqual(512, mb_strlen($messages->description()));
        $this->assertLessThanOrEqual(120, mb_strlen($messages->shortDescription()));
    }

    private function keyboardMessages(): int
    {
        return Http::recorded(fn ($r) => isset($r['reply_markup']['keyboard']))->count();
    }

    public function test_outdated_keyboard_is_replaced_once_on_any_button(): void
    {
        $this->fake();
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private', 'keyboard_version' => 'old']);
        $chat->subscriptions()->create(['queue' => '1.1']);
        $this->update($this->message(Keyboards::SETTINGS));
        $this->assertSame(1, $this->keyboardMessages());
        $this->update($this->message(Keyboards::GUIDE));
        $this->assertSame(1, $this->keyboardMessages());
        $this->assertSame(Keyboards::version(5), $chat->refresh()->keyboard_version);

        // A schedule carries the keyboard, so no extra message is needed.
        $chat->update(['keyboard_version' => 'old']);
        $this->update($this->message(Keyboards::SCHEDULE));
        $this->assertSame(2, $this->keyboardMessages());
        Http::assertSentCount(9);
    }

    public function test_repeated_start_shows_the_selection_and_keeps_queues(): void
    {
        $this->fake();
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private']);
        $chat->subscriptions()->create(['queue' => '5.2']);
        $this->update(['my_chat_member' => ['chat' => ['id' => 5, 'type' => 'private'], 'new_chat_member' => ['status' => 'kicked']]]);
        $this->assertFalse($chat->refresh()->active);

        for ($i = 1; $i <= 2; $i++) {
            $this->update($this->message('/start'));
            $this->assertSame($i, $this->keyboardMessages());
            $this->assertCount($i, Http::recorded(fn ($r) => in_array('✅ 5.2', array_column(array_merge(...($r['reply_markup']['inline_keyboard'] ?? [[]])), 'text'), true)));
        }

        $this->assertTrue($chat->refresh()->active);
        $this->assertSame(['5.2'], $chat->subscriptions->pluck('queue')->all());
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Довідник'));
    }

    private function delivery(): Delivery
    {
        return Delivery::query()->create(['telegram_id' => 5, 'dedupe_key' => uniqid(), 'text' => 't', 'available_at' => now()]);
    }

    public function test_sound_can_be_turned_off_without_losing_messages(): void
    {
        $this->fake();
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private']);
        $chat->subscriptions()->create(['queue' => '1.1']);
        $sentSilently = fn (bool $silent) => Http::assertSent(fn ($r) => ($r['text'] ?? '') === 't' && $r['disable_notification'] === $silent);

        $this->delivery();
        app(DeliverySender::class)->flush();
        $sentSilently(false);

        $this->update($this->press('mute'));
        $this->assertTrue($chat->refresh()->silent);
        $this->assertNotFalse($chat->active);
        $this->delivery();
        app(DeliverySender::class)->flush();
        $sentSilently(true);

        // Service alerts always sound.
        Delivery::query()->create(['telegram_id' => 5, 'dedupe_key' => 'admin:x', 'text' => 'alert', 'available_at' => now()]);
        app(DeliverySender::class)->flush();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === 'alert' && $r['disable_notification'] === false);

        $this->update($this->press('sound'));
        $this->assertFalse($chat->refresh()->silent);
    }

    public function test_old_settings_button_still_works(): void
    {
        $this->assertSame('settings', Keyboards::screen('⚙️ Налаштування', 5));
        $this->assertSame('settings', Keyboards::screen('⚙ Налаштування', 5));
    }

    public function test_statistics_are_shown_to_the_admin_only(): void
    {
        $this->fake();
        $this->assertNotContains(Keyboards::STATISTICS, array_column(array_merge(...Keyboards::bottom(5)['keyboard']), 'text'));
        $this->assertContains(Keyboards::STATISTICS, array_column(array_merge(...Keyboards::bottom(999)['keyboard']), 'text'));
        $this->assertNotSame(Keyboards::version(5), Keyboards::version(999));

        foreach ([5 => ['5.2', '1.1'], 999 => ['5.2'], 6 => ['2.1'], 7 => []] as $id => $queues) {
            $chat = Chat::query()->create(['telegram_id' => $id, 'type' => 'private', 'active' => $id !== 6, 'silent' => $id === 5]);
            foreach ($queues as $queue) {
                $chat->subscriptions()->create(['queue' => $queue]);
            }
        }

        $this->update($this->message(Keyboards::STATISTICS));
        $this->update($this->press('stats'));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Статистика'));

        $this->update($this->message(Keyboards::STATISTICS, 'private', 999));
        Http::assertSent(fn ($r) => ($r['chat_id'] ?? 0) === 999
            && str_contains($r['text'] ?? '', 'Користувачів: <b>4</b>, отримують: <b>2</b>')
            && str_contains($r['text'], 'без звуку: 1')
            && str_contains($r['text'], "1.1 — 1\n5.2 — 2\n")
            && ! str_contains($r['text'], '2.1'));
    }

    public function test_statistics_screen_is_available_without_an_admin_id_to_nobody(): void
    {
        config(['telegram.admin_id' => null]);
        $this->assertFalse(Keyboards::isAdmin(0));
        $this->assertNull(Keyboards::screen(Keyboards::STATISTICS, 999));
    }

    public function test_outdated_delivery_records_are_removed(): void
    {
        foreach (['old' => [31, 'sent'], 'failed' => [31, 'failed'], 'expired' => [31, 'expired'], 'waiting' => [31, 'pending'], 'recent' => [29, 'sent']] as $key => [$days, $status]) {
            $delivery = new Delivery(['telegram_id' => 5, 'dedupe_key' => $key, 'text' => 't', 'status' => $status, 'available_at' => now()]);
            $delivery->created_at = now()->subDays($days);
            $delivery->save();
        }

        app(DeliveryCleanup::class)->runDue();
        $this->assertEqualsCanonicalizing(['waiting', 'recent'], Delivery::query()->pluck('dedupe_key')->all());

        config(['telegram.deliveries_keep_days' => 10]);
        app(DeliveryCleanup::class)->runDue();
        $this->assertSame(['waiting'], Delivery::query()->pluck('dedupe_key')->all());
    }

    public function test_blocked_chat_is_deactivated(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked'], 403)]);
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private']);
        $delivery = $this->delivery();
        app(DeliverySender::class)->flush();

        $this->assertFalse($chat->refresh()->active);
        $this->assertSame(Delivery::FAILED, $delivery->refresh()->status);
    }

    public function test_rate_limit_is_respected_and_message_is_sent_later(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many', 'parameters' => ['retry_after' => 30]], 429)
            ->push(['ok' => true, 'result' => []]);
        $delivery = $this->delivery();
        app(DeliverySender::class)->flush();
        app(DeliverySender::class)->flush();
        $this->assertSame(Delivery::PENDING, $delivery->refresh()->status);

        $this->travel(31)->seconds();
        app(DeliverySender::class)->flush();
        $this->assertSame(Delivery::SENT, $delivery->refresh()->status);
    }

    public function test_token_is_not_exposed_in_errors(): void
    {
        Http::fake(fn () => throw new ConnectionException('Failed https://api.telegram.org/bottest-token/getMe'));

        try {
            app(TelegramClient::class)->call('getMe');
            $this->fail();
        } catch (TelegramException $e) {
            $this->assertStringNotContainsString('test-token', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }
}
