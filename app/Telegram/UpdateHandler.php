<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\Schedule;
use App\Schedule\Queues;

final class UpdateHandler
{
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
        if (isset($message['migrate_to_chat_id'])) {
            ChatMigrator::migrate((int) $message['chat']['id'], (int) $message['migrate_to_chat_id']);

            return;
        }
        if (! preg_match('/^\/([a-z]+)(?:@(\w+))?(?:\s|$)/i', $message['text'] ?? '', $match)) {
            return;
        }
        if (($match[2] ?? '') !== '' && strcasecmp($match[2], $botUsername) !== 0) {
            return;
        }

        $chatId = (int) $message['chat']['id'];
        $type = $message['chat']['type'];
        $command = strtolower($match[1]);

        if (in_array($command, ['start', 'queue', 'stop'], true) && ! $this->mayConfigure($message['chat'], $message['from']['id'] ?? null, $message['sender_chat']['id'] ?? null)) {
            $this->telegram->send($chatId, $this->messages->adminsOnly());

            return;
        }

        match ($command) {
            'start', 'queue' => $this->showQueues($chatId, $type),
            'schedule' => $this->showSchedule($chatId),
            'stop' => $this->stop($chatId),
            'help' => $this->telegram->send($chatId, $this->messages->help()),
            default => $type === 'private' ? $this->telegram->send($chatId, $this->messages->help()) : null,
        };
    }

    /** @param array<string, mixed> $query */
    private function callback(array $query): void
    {
        $chat = $query['message']['chat'] ?? null;
        $data = (string) ($query['data'] ?? '');

        if ($chat === null) {
            return;
        }
        if (! $this->mayConfigure($chat, $query['from']['id'])) {
            $this->answer($query['id'], $this->messages->adminsOnly());

            return;
        }

        if ($data === 'done') {
            $this->answer($query['id']);
            $this->telegram->call('editMessageReplyMarkup', [
                'chat_id' => $chat['id'],
                'message_id' => $query['message']['message_id'],
                'reply_markup' => ['inline_keyboard' => []],
            ]);
            $this->showSchedule((int) $chat['id']);

            return;
        }

        $queue = str_starts_with($data, 'q:') ? substr($data, 2) : '';

        if (! in_array($queue, Queues::ALL, true)) {
            $this->answer($query['id']);

            return;
        }

        $model = $this->activate((int) $chat['id'], $chat['type']);

        if ($model->subscriptions()->where('queue', $queue)->delete() === 0) {
            $model->subscriptions()->create(['queue' => $queue]);
        }

        $this->answer($query['id']);
        $this->telegram->call('editMessageReplyMarkup', [
            'chat_id' => $chat['id'],
            'message_id' => $query['message']['message_id'],
            'reply_markup' => $this->keyboard($model),
        ]);
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
            $this->showQueues($chatId, $member['chat']['type']);
        }
    }

    private function showQueues(int $chatId, string $type): void
    {
        $chat = $this->activate($chatId, $type);
        $this->telegram->send($chatId, $this->messages->chooseQueue(), ['reply_markup' => $this->keyboard($chat)]);
    }

    private function showSchedule(int $chatId): void
    {
        $queues = Chat::query()->where('telegram_id', $chatId)->first()?->subscriptions->pluck('queue')->all() ?? [];
        $today = now(config('telegram.timezone'));
        $days = Schedule::query()->whereBetween('date', [$today->format('Y-m-d'), $today->addDay()->format('Y-m-d')])
            ->orderBy('date')->get()->mapWithKeys(fn (Schedule $s) => [$s->date => $s->day()])->all();

        $this->telegram->send($chatId, $this->messages->currentSchedule($days, $queues));
    }

    private function stop(int $chatId): void
    {
        Chat::query()->where('telegram_id', $chatId)->update(['active' => false]);
        $this->telegram->send($chatId, $this->messages->stopped());
    }

    private function activate(int $chatId, string $type): Chat
    {
        return Chat::query()->updateOrCreate(['telegram_id' => $chatId], ['type' => $type, 'active' => true]);
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

    /** @return array<string, mixed> */
    private function keyboard(Chat $chat): array
    {
        $selected = $chat->subscriptions()->pluck('queue')->all();
        $buttons = array_map(fn (string $queue) => [
            'text' => (in_array($queue, $selected, true) ? '✅ ' : '').$queue,
            'callback_data' => "q:{$queue}",
        ], Queues::ALL);

        return ['inline_keyboard' => [...array_chunk($buttons, 4), [['text' => 'Готово', 'callback_data' => 'done']]]];
    }

    private function answer(string $id, ?string $text = null): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text]));
        } catch (TelegramException) {
            // The query may have expired; the action itself still matters.
        }
    }
}
