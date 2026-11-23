<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\SourceState;
use App\Schedule\OutageTimeline;
use App\Schedule\Queues;

final class UpdateHandler
{
    /** Buttons of messages sent by earlier versions => current actions. */
    private const LEGACY = ['schedule' => 'menu', 'buttons' => 'help', 'remind' => 'settings'];

    private const LEGACY_NEW_PREFIX = 'new:';

    public function __construct(private TelegramClient $telegram, private Messages $messages) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
        } elseif (isset($update['my_chat_member'])) {
            $this->membership($update['my_chat_member']);
        } elseif (isset($update['message'])) {
            $this->message($update['message']);
        }
    }

    /** @param array<string, mixed> $message */
    private function message(array $message): void
    {
        $text = trim($message['text'] ?? '');

        // Only private chats are supported.
        if ($message['chat']['type'] !== 'private' || $text === '') {
            return;
        }

        $chat = $this->chat((int) $message['chat']['id']);

        if ($text === Keyboards::MENU) {
            $this->openMenu($chat);
            $this->delete($chat, (int) $message['message_id']);

            return;
        }
        if (! $chat->subscriptions()->exists()) {
            $this->welcome($chat);

            return;
        }
        if ($text === '/start') {
            $chat->update(['active' => true]);
        }

        // The bottom button may be missing, so it is sent again before the menu.
        $this->telegram->send($chat->telegram_id, $this->messages->menuHint(), ['reply_markup' => Keyboards::bottom()]);
        $this->openMenu($chat);
    }

    /** @param array<string, mixed> $query */
    private function callback(array $query): void
    {
        $message = $query['message'] ?? null;

        if ($message === null || $message['chat']['type'] !== 'private') {
            return;
        }

        $chat = $this->chat((int) $message['chat']['id']);
        $messageId = (int) $message['message_id'];
        $data = (string) ($query['data'] ?? '');

        if (str_starts_with($data, self::LEGACY_NEW_PREFIX)) {
            $this->answer($query['id']);
            $this->openMenu($chat);

            return;
        }

        $data = self::LEGACY[$data] ?? $data;
        [$toast, $alert] = [null, false];

        if (str_starts_with($data, 'q:')) {
            $toast = $this->toggleQueue($chat, substr($data, 2));
            $data = 'queue';
        } elseif (str_starts_with($data, 'r:')) {
            $toast = $this->setReminder($chat, (int) substr($data, 2));
            $data = 'settings';
        } elseif ($data === 'stop' || $data === 'enable') {
            $chat->update(['active' => $data === 'enable']);
            $data = 'settings';
        } elseif ($data === 'done') {
            if ($chat->subscriptions()->exists()) {
                $data = 'menu';
            } else {
                [$toast, $alert, $data] = [$this->messages->nothingSelected(), true, ''];
            }
        }

        $this->answer($query['id'], $toast, $alert);

        match ($data) {
            'menu' => $this->showMenu($chat, $messageId),
            'queue' => $this->showQueues($chat, $messageId),
            'settings' => $this->render($chat, $this->messages->settings($chat->active, $chat->remind_minutes), Keyboards::settings($chat), $messageId),
            'help' => $this->render($chat, $this->messages->guide(), null, $messageId),
            'sources' => $this->render($chat, $this->messages->sources(), null, $messageId),
            default => null,
        };
    }

    /** @param array<string, mixed> $member */
    private function membership(array $member): void
    {
        $chatId = (int) $member['chat']['id'];

        if (in_array($member['new_chat_member']['status'], ['kicked', 'left'], true)) {
            Chat::query()->where('telegram_id', $chatId)->update(['active' => false]);
        } elseif ($member['chat']['type'] !== 'private') {
            $this->telegram->call('leaveChat', ['chat_id' => $chatId]);
        }
    }

    /** First contact: the guide brings the bottom button, the selection follows at once. */
    private function welcome(Chat $chat): void
    {
        $chat->update(['active' => true]);
        $this->telegram->send($chat->telegram_id, $this->messages->guide(), ['reply_markup' => Keyboards::bottom()]);
        $this->replaceMenu($chat, fn () => $this->showQueues($chat));
    }

    private function openMenu(Chat $chat): void
    {
        $this->replaceMenu($chat, fn () => $this->showMenu($chat));
    }

    /**
     * Keeps a single menu in the chat, always at the bottom.
     *
     * @param  callable(): int  $show  sends the menu and returns its message id
     */
    private function replaceMenu(Chat $chat, callable $show): void
    {
        $previous = $chat->menu_message_id;
        $chat->update(['menu_message_id' => $show()]);

        if ($previous !== null) {
            $this->delete($chat, $previous);
        }
    }

    private function showMenu(Chat $chat, ?int $messageId = null): int
    {
        $queues = $chat->subscriptions()->pluck('queue')->all();

        if ($queues === []) {
            return $this->showQueues($chat, $messageId);
        }

        // Only stored data is used; the worker keeps it up to date.
        $checkedAt = SourceState::query()->max('succeeded_at');
        $text = $this->messages->currentSchedule(OutageTimeline::load(), $queues, $chat->active, $checkedAt);

        return $this->render($chat, $text, Keyboards::menu(), $messageId);
    }

    private function showQueues(Chat $chat, ?int $messageId = null): int
    {
        return $this->render($chat, $this->messages->chooseQueue(), Keyboards::queues($chat), $messageId);
    }

    private function toggleQueue(Chat $chat, string $queue): ?string
    {
        if (! in_array($queue, Queues::ALL, true)) {
            return null;
        }

        $added = $chat->subscriptions()->where('queue', $queue)->delete() === 0;

        if ($added) {
            $chat->subscriptions()->create(['queue' => $queue]);
        }

        return $this->messages->queueToggled($queue, $added);
    }

    private function setReminder(Chat $chat, int $minutes): ?string
    {
        if ($minutes !== 0 && ! in_array($minutes, config('telegram.remind_minutes'), true)) {
            return null;
        }

        $chat->update(['remind_minutes' => $minutes ?: null]);

        return 'Нагадування: '.$this->messages->reminderLabel($chat->remind_minutes);
    }

    /**
     * Replaces the given message or sends a new one.
     *
     * @param  array<string, mixed>|null  $keyboard
     * @return int id of the shown message
     */
    private function render(Chat $chat, string $text, ?array $keyboard, ?int $messageId): int
    {
        $extra = $keyboard === null ? [] : ['reply_markup' => $keyboard];

        if ($messageId !== null) {
            try {
                $this->telegram->call('editMessageText', [
                    'chat_id' => $chat->telegram_id,
                    'message_id' => $messageId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                    ...$extra,
                ]);

                return $messageId;
            } catch (TelegramException $e) {
                // A double tap leaves the message unchanged, which Telegram reports as an error.
                if (str_contains($e->getMessage(), 'not modified')) {
                    return $messageId;
                }
                if ($e->getCode() !== 400) {
                    throw $e;
                }
                // The message can no longer be edited, so a new one is sent.
            }
        }

        return (int) ($this->telegram->send($chat->telegram_id, $text, $extra)['message_id'] ?? 0);
    }

    private function delete(Chat $chat, int $messageId): void
    {
        try {
            $this->telegram->call('deleteMessage', ['chat_id' => $chat->telegram_id, 'message_id' => $messageId]);
        } catch (TelegramException) {
            // Cleanup only: the message may be already deleted or too old.
        }
    }

    private function chat(int $chatId): Chat
    {
        return Chat::query()->firstOrCreate(['telegram_id' => $chatId], ['type' => 'private', 'active' => true]);
    }

    private function answer(string $id, ?string $text = null, bool $alert = false): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert]));
        } catch (TelegramException) {
            // The query may have expired; the action itself still matters.
        }
    }
}
