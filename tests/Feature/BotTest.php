<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Delivery;
use App\Telegram\DeliverySender;
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

    private string $memberStatus = 'member';

    private function fake(): void
    {
        Http::fake(fn ($request) => Http::response(['ok' => true, 'result' => str_ends_with($request->url(), 'getChatMember') ? ['status' => $this->memberStatus] : true]));
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

    public function test_group_settings_are_for_admins(): void
    {
        $this->fake();
        $this->update($this->message('/queue', 'supergroup', -5));
        $this->update($this->press('q:1.1', 'supergroup', -5));
        $this->assertSame(0, Chat::query()->count());

        $this->memberStatus = 'administrator';
        $this->update($this->press('q:1.1', 'supergroup', -5));
        $this->assertSame(1, Chat::query()->sole()->subscriptions()->count());
    }

    public function test_anonymous_group_admin_is_allowed_but_linked_channel_is_not(): void
    {
        $this->fake();
        $this->update($this->message('/queue', 'supergroup', -5, ['sender_chat' => ['id' => -9]]));
        $this->assertSame(0, Chat::query()->count());
        $this->update($this->message('/queue', 'supergroup', -5, ['sender_chat' => ['id' => -5]]));
        $this->assertSame(1, Chat::query()->count());
    }

    public function test_commands_for_other_bots_are_ignored(): void
    {
        $this->fake();
        $this->update($this->message('/start@other_bot', 'group', -5));
        Http::assertNothingSent();
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

    public function test_group_migration_keeps_subscriptions(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'upgraded', 'parameters' => ['migrate_to_chat_id' => -100]], 400)
            ->push(['ok' => true, 'result' => []]);
        $chat = Chat::query()->create(['telegram_id' => 5, 'type' => 'group']);
        $chat->subscriptions()->create(['queue' => '1.1']);
        $delivery = $this->delivery();
        app(DeliverySender::class)->flush();
        app(DeliverySender::class)->flush();

        $this->assertSame(-100, $chat->refresh()->telegram_id);
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
