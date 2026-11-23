<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Delivery;
use App\Telegram\DeliverySender;
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
        Http::fake(fn () => Http::response(['ok' => true, 'result' => true]));
    }

    private function update(array $update): void
    {
        app(UpdateHandler::class)->handle($update, 'zoe_bot');
    }

    private function message(string $text, string $type = 'private', int $id = 5, array $extra = []): array
    {
        return ['message' => ['text' => $text, 'chat' => ['id' => $id, 'type' => $type], 'from' => ['id' => 7], ...$extra]];
    }

    private function press(string $data, string $type = 'private', int $id = 5): array
    {
        return ['callback_query' => ['id' => 'x', 'data' => $data, 'from' => ['id' => 7], 'message' => ['message_id' => 1, 'chat' => ['id' => $id, 'type' => $type]]]];
    }

    public function test_user_selects_several_queues_and_stops(): void
    {
        $this->fake();
        $this->update($this->message('/start'));
        $this->update($this->press('queue'));
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', '<a href="https://www.zoe.com.ua/outage/">'));
        foreach (['q:1.1', 'q:5.2', 'q:1.1', 'q:6.1', 'q:9.9'] as $data) {
            $this->update($this->press($data));
        }
        $chat = Chat::query()->sole();
        $this->assertSame(['5.2', '6.1'], $chat->subscriptions->pluck('queue')->all());

        $this->update($this->press('done'));
        $this->update($this->message('/schedule'));
        $this->update($this->message('/stop'));
        $this->assertFalse($chat->refresh()->active);
    }

    public function test_commands_for_other_bots_are_ignored(): void
    {
        $this->fake();
        $this->update($this->message('/start@other_bot', 'group', -5));
        Http::assertNothingSent();
    }

    private function hasButton($request, string $data): bool
    {
        return in_array($data, array_column(array_merge(...($request['reply_markup']['inline_keyboard'] ?? [[]])), 'callback_data'), true);
    }

    public function test_menu_is_edited_in_place_and_toggles_notifications(): void
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

    public function test_any_private_text_opens_menu_and_notification_button_sends_new_message(): void
    {
        $this->fake();
        Chat::query()->create(['telegram_id' => 5, 'type' => 'private'])->subscriptions()->create(['queue' => '1.1']);
        $this->update($this->message('привіт'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'sendMessage') && $this->hasButton($r, 'queue'));

        $this->update($this->press('new:schedule'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'sendMessage') && $this->hasButton($r, 'menu'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'editMessageText'));
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

    public function test_sources_screen_and_manual_link_to_enabled_sources(): void
    {
        config(['sources.sources.telegram.priority' => 200]);
        $messages = app(Messages::class);

        foreach ([$messages->sources(), $messages->manual()] as $text) {
            $this->assertStringContainsString('<a href="https://www.zoe.com.ua/outage/">', $text);
            $this->assertStringContainsString('<a href="https://t.me/zoe_alarm">', $text);
            $this->assertStringNotContainsString('груп', $text);
            $this->assertLessThan(strpos($text, 'zoe.com.ua'), strpos($text, 't.me'));
        }

        config(['sources.sources.telegram.enabled' => false]);
        $this->assertStringNotContainsString('t.me', $messages->sources());
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
