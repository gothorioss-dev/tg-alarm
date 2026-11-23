<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Delivery;
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
        $this->assertFalse($chat->refresh()->active);
        $this->update($this->message('/start'));
        $this->assertTrue($chat->refresh()->active);
    }

    private function hasButton($request, string $data): bool
    {
        return in_array($data, array_column(array_merge(...($request['reply_markup']['inline_keyboard'] ?? [[]])), 'callback_data'), true);
    }

    public function test_screens_are_edited_in_place_and_toggles_notifications(): void
    {
        $this->fake();
        $this->update($this->message('/start'));
        $this->update($this->press('done'));
        Http::assertSent(fn ($r) => ($r['show_alert'] ?? false) === true);

        $this->update($this->press('q:5.2'));
        $this->update($this->press('stop'));
        $chat = Chat::query()->sole();
        $this->assertFalse($chat->active);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'editMessageText') && $this->hasButton($r, 'enable'));

        // Changing queues does not silently turn notifications back on.
        $this->update($this->press('q:6.1'));
        $this->assertFalse($chat->refresh()->active);

        $this->update($this->press('enable'));
        $this->assertTrue($chat->refresh()->active);
        $this->assertSame(['5.2', '6.1'], $chat->subscriptions->pluck('queue')->all());
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

    public function test_schedule_has_no_inline_buttons_and_a_single_screen_is_kept(): void
    {
        $this->fake();
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'private', 'menu_message_id' => 40]);
        $chat->subscriptions()->create(['queue' => '1.1']);
        $this->update($this->message(Keyboards::SCHEDULE));

        $this->assertSame(60, $chat->refresh()->menu_message_id);
        foreach ([40, 50] as $id) {
            Http::assertSent(fn ($r) => str_ends_with($r->url(), 'deleteMessage') && $r['message_id'] === $id);
        }
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'sendMessage') && ! isset($r['reply_markup']));
        Http::assertNotSent(fn ($r) => isset($r['reply_markup']['keyboard']));
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

        foreach (array_merge(...Keyboards::bottom()['keyboard']) as $button) {
            $this->assertNotNull(Keyboards::screen($button['text']));
            $this->assertNotNull(Keyboards::screen(str_replace("\u{FE0F}", '', $button['text'])));
            $this->update($this->message($button['text']));
        }

        Http::assertSent(fn ($r) => $this->hasButton($r, 'q:1.1'));
        Http::assertSent(fn ($r) => $this->hasButton($r, 'r:30'));
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Довідник'));
        Http::assertNotSent(fn ($r) => isset($r['reply_markup']['keyboard']));
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

    private function delivery(): Delivery
    {
        return Delivery::query()->create(['telegram_id' => 5, 'dedupe_key' => uniqid(), 'text' => 't', 'available_at' => now()]);
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
