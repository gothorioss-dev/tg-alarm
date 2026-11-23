<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\SourceState;
use App\Schedule\OutageTimeline;
use App\Schedule\Queues;

final class UpdateHandler
{
    private const ACTIONS = ['start', 'queue', 'schedule', 'stop', 'enable', 'help', 'menu', 'buttons', 'settings', 'remind', 'sources'];

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

        // Only private chats are supported.
        if ($chat['type'] !== 'private') {
            return;
        }

        $text = trim($message['text'] ?? '');
        $action = null;

        if (preg_match('/^\/([a-z]+)(?:@(\w+))?(?:\s|$)/i', $text, $match)) {
            if (($match[2] ?? '') !== '' && strcasecmp($match[2], $botUsername) !== 0) {
                return;
            }
            $action = strtolower($match[1]);
        }
        if (! in_array($action, self::ACTIONS, true)) {
            if ($text === '') {
                return;
            }
            // The first message of a new user leads to the guide.
            $action = $this->hasQueues((int) $chat['id']) ? 'menu' : 'start';
        }

        $this->run($action, (int) $chat['id'], $chat['type']);
    }

    /** @param array<string, mixed> $query */
    private function callback(array $query): void
    {
        $chat = $query['message']['chat'] ?? null;
        $data = (string) ($query['data'] ?? '');
        $newMessage = str_starts_with($data, Keyboards::NEW_PREFIX);
        $data = $newMessage ? substr($data, strlen(Keyboards::NEW_PREFIX)) : $data;

        if ($chat === null || $chat['type'] !== 'private') {
            return;
        }

        $chatId = (int) $chat['id'];

        if ($data === 'done') {
            $this->finishSelection($query, $chatId, $chat['type']);

            return;
        }
        if (in_array($data, self::ACTIONS, true)) {
            // Refreshing an unchanged schedule is otherwise invisible.
            $this->answer($query['id'], $data === 'schedule' && ! $newMessage ? $this->messages->scheduleShown() : null);
            $this->run($data, $chatId, $chat['type'], $newMessage ? null : (int) $query['message']['message_id']);

            return;
        }

        if (str_starts_with($data, 'r:')) {
            $this->setReminder($query, $chatId, $chat['type'], (int) substr($data, 2));

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
        $this->render($chatId, $this->messages->chooseQueue(), Keyboards::queues($model), (int) $query['message']['message_id']);
    }

    /** @param array<string, mixed> $query */
    private function finishSelection(array $query, int $chatId, string $type): void
    {
        if (! $this->chat($chatId, $type)->subscriptions()->exists()) {
            $this->answer($query['id'], $this->messages->nothingSelected(), alert: true);

            return;
        }

        $this->answer($query['id']);
        $this->showSchedule($chatId, $type, (int) $query['message']['message_id']);
    }

    /** @param array<string, mixed> $member */
    private function membership(array $member): void
    {
        $chatId = (int) $member['chat']['id'];
        $status = $member['new_chat_member']['status'];

        if (in_array($status, ['kicked', 'left'], true)) {
            Chat::query()->where('telegram_id', $chatId)->update(['active' => false]);
        } elseif ($member['chat']['type'] !== 'private') {
            $this->telegram->call('leaveChat', ['chat_id' => $chatId]);
        }
    }

    /** With a message id the screen replaces that message, otherwise it is sent as a new one. */
    private function run(string $action, int $chatId, string $type, ?int $messageId = null): void
    {
        match ($action) {
            'start' => $this->start($chatId, $type),
            'queue' => $this->showQueues($chatId, $type, $messageId),
            'schedule' => $this->showSchedule($chatId, $type, $messageId),
            'stop' => $this->setActive($chatId, $type, false, $messageId),
            'enable' => $this->setActive($chatId, $type, true, $messageId),
            'menu' => $this->showMenu($chatId, $type, $messageId),
            // 'buttons' is kept for messages sent before the screens were merged.
            'help', 'buttons' => $this->render($chatId, $this->messages->guide(), Keyboards::guide($this->hasQueues($chatId)), $messageId),
            'sources' => $this->render($chatId, $this->messages->sources(), Keyboards::back(), $messageId),
            'settings' => $this->showSettings($chatId, $type, $messageId),
            'remind' => $this->showReminders($chatId, $type, $messageId),
        };
    }

    private function start(int $chatId, string $type): void
    {
        $chat = $this->chat($chatId, $type);
        $chat->update(['active' => true]);

        $chat->subscriptions()->exists()
            ? $this->showMenu($chatId, $type)
            : $this->run('help', $chatId, $type);
    }

    private function showMenu(int $chatId, string $type, ?int $messageId = null): void
    {
        $chat = $this->chat($chatId, $type);
        $text = $this->messages->menu($chat->subscriptions->pluck('queue')->all(), $chat->active, $chat->remind_minutes);
        $this->render($chatId, $text, Keyboards::menu(), $messageId);
    }

    private function showQueues(int $chatId, string $type, ?int $messageId = null): void
    {
        $this->render($chatId, $this->messages->chooseQueue(), Keyboards::queues($this->chat($chatId, $type)), $messageId);
    }

    private function showSchedule(int $chatId, string $type, ?int $messageId = null): void
    {
        $chat = $this->chat($chatId, $type);
        $queues = $chat->subscriptions->pluck('queue')->all();
        $checkedAt = SourceState::query()->max('succeeded_at');
        $this->render($chatId, $this->messages->currentSchedule(OutageTimeline::load(), $queues, $chat->active, $checkedAt), Keyboards::schedule(), $messageId);
    }

    private function setActive(int $chatId, string $type, bool $active, ?int $messageId): void
    {
        $this->chat($chatId, $type)->update(['active' => $active]);
        $this->showSettings($chatId, $type, $messageId);
    }

    /** @param array<string, mixed> $keyboard */
    private function render(int $chatId, string $text, array $keyboard, ?int $messageId): void
    {
        if ($messageId === null) {
            $this->telegram->send($chatId, $text, ['reply_markup' => $keyboard]);

            return;
        }

        try {
            $this->telegram->call('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => $keyboard,
            ]);
        } catch (TelegramException $e) {
            // A double tap leaves the message unchanged, which Telegram reports as an error.
            if (str_contains($e->getMessage(), 'not modified')) {
                return;
            }
            if ($e->getCode() !== 400) {
                throw $e;
            }
            // The message can no longer be edited.
            $this->telegram->send($chatId, $text, ['reply_markup' => $keyboard]);
        }
    }

    private function chat(int $chatId, string $type): Chat
    {
        return Chat::query()->firstOrCreate(['telegram_id' => $chatId], ['type' => $type, 'active' => true]);
    }

    private function answer(string $id, ?string $text = null, bool $alert = false): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert]));
        } catch (TelegramException) {
            // The query may have expired; the action itself still matters.
        }
    }

    private function hasQueues(int $chatId): bool
    {
        return Chat::query()->where('telegram_id', $chatId)->whereHas('subscriptions')->exists();
    }

    private function showSettings(int $chatId, string $type, ?int $messageId = null): void
    {
        $chat = $this->chat($chatId, $type);
        $keyboard = Keyboards::settings($chat, $this->messages->reminderLabel($chat->remind_minutes));
        $this->render($chatId, $this->messages->settings($chat->active, $chat->remind_minutes), $keyboard, $messageId);
    }

    private function showReminders(int $chatId, string $type, ?int $messageId = null): void
    {
        $chat = $this->chat($chatId, $type);
        $this->render($chatId, $this->messages->reminderSettings($chat->active), Keyboards::reminders($chat), $messageId);
    }

    /** @param array<string, mixed> $query */
    private function setReminder(array $query, int $chatId, string $type, int $minutes): void
    {
        if ($minutes !== 0 && ! in_array($minutes, config('telegram.remind_minutes'), true)) {
            $this->answer($query['id']);

            return;
        }

        $chat = $this->chat($chatId, $type);
        $chat->update(['remind_minutes' => $minutes ?: null]);
        $this->answer($query['id'], 'Нагадування: '.$this->messages->reminderLabel($chat->remind_minutes));
        $this->showReminders($chatId, $type, (int) $query['message']['message_id']);
    }
}
