<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\Schedule;
use App\Schedule\Queues;

final class UpdateHandler
{
    /** Actions that only show information and are open to every group member. */
    private const PUBLIC_ACTIONS = ['schedule', 'help'];

    private const ACTIONS = ['start', 'queue', 'schedule', 'stop', 'enable', 'help'];

    public function __construct(private TelegramClient $telegram, private Messages $messages) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update, string $botUsername): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
        } elseif (isset($update['my_chat_member'])) {
            $this->membership($update['my_chat_member']);
        } elseif (isset($update['message'])) {
            $this->message($update['message'], $botUsername);
        }
    }

    /** @param array<string, mixed> $message */
    private function message(array $message, string $botUsername): void
    {
        $chat = $message['chat'];
        $private = $chat['type'] === 'private';

        if (isset($message['migrate_to_chat_id'])) {
            ChatMigrator::migrate((int) $chat['id'], (int) $message['migrate_to_chat_id']);

            return;
        }

        $text = trim($message['text'] ?? '');
        $action = $private ? (Keyboards::ACTIONS[$text] ?? null) : null;

        if ($action === null && preg_match('/^\/([a-z]+)(?:@(\w+))?(?:\s|$)/i', $text, $match)) {
            if (($match[2] ?? '') !== '' && strcasecmp($match[2], $botUsername) !== 0) {
                return;
            }
            $action = strtolower($match[1]);
        }
        if (! in_array($action, self::ACTIONS, true)) {
            if (! $private || $text === '') {
                return;
            }
            $action = 'help';
        }

        if (! in_array($action, self::PUBLIC_ACTIONS, true)
            && ! $this->mayConfigure($chat, $message['from']['id'] ?? null, $message['sender_chat']['id'] ?? null)) {
            $this->telegram->send((int) $chat['id'], $this->messages->adminsOnly());

            return;
        }

        $this->run($action, (int) $chat['id'], $chat['type']);
    }

    /** @param array<string, mixed> $query */
    private function callback(array $query): void
    {
        $chat = $query['message']['chat'] ?? null;
        $data = (string) ($query['data'] ?? '');

        if ($chat === null) {
            return;
        }
        if (! in_array($data, self::PUBLIC_ACTIONS, true) && ! $this->mayConfigure($chat, $query['from']['id'])) {
            $this->answer($query['id'], $this->messages->adminsOnly(), alert: true);

            return;
        }

        $chatId = (int) $chat['id'];

        if ($data === 'done') {
            $this->finishSelection($query, $chatId, $chat['type']);

            return;
        }
        if (in_array($data, self::ACTIONS, true)) {
            $this->answer($query['id']);
            $this->run($data, $chatId, $chat['type']);

            return;
        }

        $queue = str_starts_with($data, 'q:') ? substr($data, 2) : '';

        if (! in_array($queue, Queues::ALL, true)) {
            $this->answer($query['id']);

            return;
        }

        $model = $this->chat($chatId, $chat['type']);
        $added = $model->subscriptions()->where('queue', $queue)->delete() === 0;

        if ($added) {
            $model->subscriptions()->create(['queue' => $queue]);
        }

        $this->answer($query['id'], $this->messages->queueToggled($queue, $added));
        $this->edit('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $query['message']['message_id'],
            'reply_markup' => Keyboards::queues($model),
        ]);
    }

    /** @param array<string, mixed> $query */
    private function finishSelection(array $query, int $chatId, string $type): void
    {
        $queues = $this->chat($chatId, $type)->subscriptions->pluck('queue')->all();

        if ($queues === []) {
            $this->answer($query['id'], $this->messages->nothingSelected(), alert: true);

            return;
        }

        $this->answer($query['id']);
        $this->edit('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $query['message']['message_id'],
            'text' => $this->messages->selected($queues),
            'parse_mode' => 'HTML',
        ]);
        $this->showSchedule($chatId, $type);
    }

    /** @param array<string, mixed> $member */
    private function membership(array $member): void
    {
        $chatId = (int) $member['chat']['id'];
        $status = $member['new_chat_member']['status'];

        if (in_array($status, ['kicked', 'left'], true)) {
            Chat::query()->where('telegram_id', $chatId)->update(['active' => false]);
        } elseif (in_array($member['chat']['type'], ['group', 'supergroup'], true)
            && in_array($member['old_chat_member']['status'] ?? '', ['left', 'kicked'], true)) {
            $this->run('start', $chatId, $member['chat']['type']);
        }
    }

    private function run(string $action, int $chatId, string $type): void
    {
        match ($action) {
            'start' => $this->start($chatId, $type),
            'queue' => $this->showQueues($chatId, $type),
            'schedule' => $this->showSchedule($chatId, $type),
            'stop' => $this->setActive($chatId, $type, false),
            'enable' => $this->setActive($chatId, $type, true),
            'help' => $this->reply($chatId, $type, $this->messages->help()),
        };
    }

    private function start(int $chatId, string $type): void
    {
        $chat = $this->chat($chatId, $type);
        $chat->update(['active' => true]);

        // One message cannot carry both the bottom keyboard and the queue buttons.
        if ($chat->subscriptions()->exists()) {
            $this->showSchedule($chatId, $type);

            return;
        }
        if ($chat->isPrivate()) {
            $this->reply($chatId, $type, $this->messages->welcome());
        }
        $this->showQueues($chatId, $type);
    }

    private function showQueues(int $chatId, string $type): void
    {
        $this->telegram->send($chatId, $this->messages->chooseQueue(), [
            'reply_markup' => Keyboards::queues($this->chat($chatId, $type)),
        ]);
    }

    private function showSchedule(int $chatId, string $type): void
    {
        $chat = $this->find($chatId);
        $queues = $chat?->subscriptions->pluck('queue')->all() ?? [];

        // Without a selection the most useful answer is the selection itself.
        if ($queues === [] && $type === 'private') {
            $this->showQueues($chatId, $type);

            return;
        }

        $today = now(config('telegram.timezone'));
        $days = Schedule::query()->whereBetween('date', [$today->format('Y-m-d'), $today->addDay()->format('Y-m-d')])
            ->orderBy('date')->get()->mapWithKeys(fn (Schedule $s) => [$s->date => $s->day()])->all();

        $this->reply($chatId, $type, $this->messages->currentSchedule($days, $queues, $chat->active ?? true));
    }

    private function setActive(int $chatId, string $type, bool $active): void
    {
        $chat = $this->chat($chatId, $type);
        $chat->update(['active' => $active]);

        $this->reply($chatId, $type, $active ? $this->messages->enabled() : $this->messages->stopped());

        if ($active && ! $chat->subscriptions()->exists()) {
            $this->showQueues($chatId, $type);
        }
    }

    /** Sends a message with the menu that fits the chat type and its current state. */
    private function reply(int $chatId, string $type, string $text): void
    {
        $this->telegram->send($chatId, $text, [
            'reply_markup' => Keyboards::menu($type === 'private', $this->find($chatId)->active ?? true),
        ]);
    }

    private function find(int $chatId): ?Chat
    {
        return Chat::query()->where('telegram_id', $chatId)->first();
    }

    private function chat(int $chatId, string $type): Chat
    {
        return Chat::query()->firstOrCreate(['telegram_id' => $chatId], ['type' => $type, 'active' => true]);
    }

    /** @param array<string, mixed> $chat */
    private function mayConfigure(array $chat, ?int $userId, ?int $senderChatId = null): bool
    {
        // In groups an anonymous administrator writes on behalf of the group itself.
        if ($chat['type'] === 'private' || $senderChatId === $chat['id']) {
            return true;
        }
        if ($userId === null || $senderChatId !== null) {
            return false;
        }

        $member = $this->telegram->call('getChatMember', ['chat_id' => $chat['id'], 'user_id' => $userId]);

        return in_array($member['status'], ['creator', 'administrator'], true);
    }

    private function answer(string $id, ?string $text = null, bool $alert = false): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert]));
        } catch (TelegramException) {
            // The query may have expired; the action itself still matters.
        }
    }

    /** @param array<string, mixed> $params */
    private function edit(string $method, array $params): void
    {
        try {
            $this->telegram->call($method, $params);
        } catch (TelegramException $e) {
            // A double tap leaves the message unchanged, which Telegram reports as an error.
            if (! str_contains($e->getMessage(), 'not modified')) {
                throw $e;
            }
        }
    }
}
