<?php

namespace App\Telegram;

use App\Address\CityRegistry;
use App\Models\Chat;
use App\Models\SourceState;
use App\Monitor\ProcessStatus;
use App\Schedule\OutageTimeline;
use App\Schedule\Queues;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UpdateHandler
{
    /** Buttons of messages sent by earlier versions => current actions. */
    private const LEGACY = ['menu' => 'schedule', 'buttons' => 'help', 'sources' => 'help', 'remind' => 'settings', 'stop' => 'settings', 'enable' => 'settings'];

    private const LEGACY_NEW_PREFIX = 'new:';

    private ?string $botLink = null;

    /** @var array<int, array{screen: string, message: ?int, due: int, until: int}> replies delayed by a Telegram limit by chat id, time in seconds */
    private array $delayed = [];

    public function __construct(private TelegramClient $telegram, private Messages $messages, private Statistics $statistics, private RateLimiter $limiter, private PressSeries $series, private ProcessStatus $status, private AddressDialog $addresses, private CityRegistry $cities, private Access $access) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (isset($update['my_chat_member'])) {
            $this->membership($update['my_chat_member']);

            return;
        }

        $query = $update['callback_query'] ?? null;
        $message = $query === null ? ($update['message'] ?? null) : ($query['message'] ?? null);

        // Only private chats are supported.
        if ($message === null || $message['chat']['type'] !== 'private' || ($query === null && trim($message['text'] ?? '') === '')) {
            return;
        }

        $chatId = (int) $message['chat']['id'];
        [$key, $pause] = $query === null ? $this->messageSeries($message) : $this->querySeries($query);
        $repeated = $this->series->isRepeat($chatId, $key, $pause);

        // Repeated messages cost nothing, so they are not limited.
        if ($repeated && $query === null) {
            if (Keyboards::screen(trim($message['text']), $chatId) !== null) {
                $this->series->collect($chatId, (int) $message['message_id']);
            }

            return;
        }
        if (! $this->allowed($chatId, $query)) {
            return;
        }
        if ($repeated) {
            $this->answer($query['id']);

            return;
        }

        $query === null ? $this->message($message) : $this->callback($query);
        $this->series->start($chatId, $key, $pause);
    }

    /** @param array<string, mixed> $message */
    private function message(array $message): void
    {
        $text = trim($message['text']);
        $chat = $this->chat((int) $message['chat']['id']);

        if (preg_match('/^\/start(\s|$)/', $text)) {
            $this->addresses->cancel($chat);
            $this->present($chat, 'start');

            return;
        }
        if ($screen = Keyboards::screen($text, $chat->telegram_id)) {
            $this->addresses->cancel($chat);
            $screen = $this->access->screen($chat, $screen) ? $screen : 'queue';
            $this->present($chat, $screen, pressedId: (int) $message['message_id']);

            return;
        }
        if ($this->addresses->isWaiting($chat) && $this->access->addresses($chat)) {
            $this->present($chat, $this->addresses->text($chat, $text) ? 'address' : 'address_draft');

            return;
        }
        if (! $chat->subscriptions()->exists()) {
            // The guide is not repeated to those who have already got it with the keyboard.
            $this->present($chat, $chat->keyboard_version === null ? 'start' : 'queue');

            return;
        }

        $this->present($chat, 'schedule');
    }

    /** @param array<string, mixed> $query */
    private function callback(array $query): void
    {
        $chat = $this->chat((int) $query['message']['chat']['id']);
        $messageId = (int) $query['message']['message_id'];
        $data = (string) ($query['data'] ?? '');

        if (str_starts_with($data, self::LEGACY_NEW_PREFIX)) {
            $this->answer($query['id']);
            $this->present($chat, 'schedule');

            return;
        }

        $data = self::LEGACY[$data] ?? $data;
        [$toast, $alert] = [null, false];

        if (! preg_match('/^(q[+-]?:|(queue|done|help|schedule)$)/', $data) && ! $this->access->screen($chat, 'settings')) {
            $this->answer($query['id'], $this->messages->queueFirst(), true);
            $this->present($chat, 'queue', $messageId);

            return;
        }
        if (! preg_match('/^as:/', $data)) {
            $this->addresses->cancel($chat);
        }

        if (preg_match('/^a\+(?::(.+))?$/', $data, $match)) {
            $limited = $this->addresses->begin($chat, $match[1] ?? null) !== null;
            [$toast, $alert, $data] = $limited ? [$this->messages->tooManyAddresses(), true, 'address'] : [null, false, 'address_draft'];
        } elseif (preg_match('/^as:(\d+)$/', $data, $match)) {
            $added = $this->addresses->chooseStreet($chat, (int) $match[1]);
            [$toast, $data] = $added ? [$this->messages->addressAdded(), 'address'] : [null, 'address_draft'];
        } elseif (preg_match('/^a-:(\d+)$/', $data, $match)) {
            $toast = $this->addresses->remove($chat, (int) $match[1]) ? $this->messages->addressRemoved() : null;
            $data = 'address';
        } elseif (preg_match('/^q([+-]?):(.*)$/', $data, $match)) {
            [$toast, $alert] = $this->selectQueue($chat, $match[2], $match[1]);
            $data = 'queue';
        } elseif (str_starts_with($data, 'r:')) {
            $toast = $this->setReminder($chat, (int) substr($data, 2));
            $data = 'settings';
        } elseif ($data === 'mute' || $data === 'sound') {
            $chat->update(['silent' => $data === 'mute']);
            $data = 'settings';
        } elseif ($data === 'done') {
            if ($chat->subscriptions()->exists()) {
                $data = 'schedule';
            } else {
                [$toast, $alert, $data] = [$this->messages->nothingSelected(), true, ''];
            }
        }

        $this->answer($query['id'], $toast, $alert);

        if ($data === 'address_draft' && $chat->menu_message_id !== $messageId) {
            // A kept list of addresses stays; the draft goes to a new service message.
            $this->present($chat, $data);
        } elseif (in_array($data, ['schedule', 'queue', 'settings', 'help', 'address', 'address_draft'], true)) {
            $this->present($chat, $data, $messageId);
        }
    }

    /** @param array<string, mixed> $member */
    private function membership(array $member): void
    {
        $chatId = (int) $member['chat']['id'];
        $this->series->forget($chatId);
        unset($this->delayed[$chatId]);

        if (in_array($member['new_chat_member']['status'], ['kicked', 'left'], true)) {
            Chat::query()->where('telegram_id', $chatId)->update([
                'active' => false,
                'keyboard_version' => null,
                'menu_message_id' => null,
            ]);
        } elseif ($member['chat']['type'] !== 'private') {
            $this->telegram->call('leaveChat', ['chat_id' => $chatId]);
        }
    }

    /** Every start leads to the queue selection; the guide is shown to those who have no queues yet. */
    private function welcome(Chat $chat): void
    {
        $isNew = ! $chat->subscriptions()->exists();
        $this->sendWithKeyboard($chat, $isNew ? $this->messages->guide() : $this->messages->welcomeBack());
        // Delayed separately, so that the guide is not repeated.
        $this->present($chat, 'queue');
    }

    /** Replaces an outdated bottom keyboard; a schedule brings the keyboard itself. */
    private function updateKeyboard(Chat $chat, string $screen): void
    {
        if ($chat->keyboard_version === Keyboards::version($chat->telegram_id)
            || ($screen === 'schedule' && $chat->subscriptions()->exists())) {
            return;
        }

        $this->sendWithKeyboard($chat, $this->messages->menuUpdated());
    }

    /** The message must stay in the chat: some clients drop the keyboard together with it. */
    private function sendWithKeyboard(Chat $chat, string $text): int
    {
        $sent = $this->telegram->send($chat->telegram_id, $text, [
            'reply_markup' => Keyboards::bottom($chat->telegram_id),
            'disable_notification' => $chat->silent,
        ]);
        $chat->update(['keyboard_version' => Keyboards::version($chat->telegram_id)]);

        return (int) ($sent['message_id'] ?? 0);
    }

    /** Replaces the previous service screen and the pressed button; kept screens stay in the chat. */
    private function open(Chat $chat, string $screen, ?int $pressedId = null): void
    {
        // Without queues the selection is shown instead of the schedule.
        if ($screen === 'schedule' && ! $chat->subscriptions()->exists()) {
            $screen = 'queue';
        }

        $previous = $chat->menu_message_id;
        $shown = $this->show($chat, $screen);
        $chat->update(['menu_message_id' => $this->kept($chat, $screen) ? null : $shown]);

        $this->delete($chat->telegram_id, array_values(array_filter([$previous, $pressedId])));
    }

    /** @return int id of the shown message */
    private function show(Chat $chat, string $screen, ?int $messageId = null): int
    {
        return match ($screen) {
            'schedule' => $this->showSchedule($chat, $messageId),
            'queue' => $this->showQueues($chat, $messageId),
            'settings' => $this->render($chat, $this->messages->settings($chat->silent, $chat->remind_minutes), Keyboards::settings($chat), $messageId),
            'help' => $this->render($chat, $this->messages->guide(), null, $messageId),
            'address' => $this->showAddresses($chat, $messageId),
            'address_draft' => $this->showAddressDraft($chat, $messageId),
            'invite' => $this->showInvite($chat, $messageId),
            'stats' => $this->render($chat, $this->messages->statistics($this->statistics->collect()), null, $messageId),
        };
    }

    private function showInvite(Chat $chat, ?int $messageId = null): int
    {
        $this->botLink ??= 'https://t.me/'.$this->telegram->call('getMe')['username'];

        return $this->render($chat, $this->messages->invite($this->botLink), Keyboards::invite($this->botLink, $this->messages->inviteText()), $messageId);
    }

    private function showSchedule(Chat $chat, ?int $messageId = null): int
    {
        $queues = $chat->subscriptions()->pluck('queue')->all();

        if ($queues === []) {
            return $this->showQueues($chat, $messageId);
        }

        // Only stored data is used; the worker keeps it up to date.
        $checkedAt = SourceState::query()->max('succeeded_at');
        $text = $this->messages->currentSchedule(OutageTimeline::load(), $queues, $checkedAt);

        return $messageId === null
            ? $this->sendWithKeyboard($chat, $text)
            : $this->render($chat, $text, null, $messageId);
    }

    private function showQueues(Chat $chat, ?int $messageId = null): int
    {
        return $this->render($chat, $this->messages->chooseQueue(), Keyboards::queues($chat), $messageId);
    }

    /** @return array{?string, bool} answer text and whether it is an alert */
    private function selectQueue(Chat $chat, string $queue, string $sign): array
    {
        if (! in_array($queue, Queues::ALL, true)) {
            return [null, false];
        }

        $exists = $chat->subscriptions()->where('queue', $queue)->exists();
        // Buttons of messages sent by earlier versions have no sign and switch the queue.
        $added = $sign === '' ? ! $exists : $sign === '+';

        if (! $added) {
            $chat->subscriptions()->where('queue', $queue)->delete();
        } elseif (! $exists) {
            // Those who selected more before the limit keep their queues.
            if ($chat->subscriptions()->count() >= (int) config('telegram.max_queues')) {
                return [$this->messages->tooManyQueues(), true];
            }

            $chat->subscriptions()->create(['queue' => $queue]);
        }

        return [$this->messages->queueToggled($queue, $added), false];
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

        return (int) ($this->telegram->send($chat->telegram_id, $text, ['disable_notification' => $chat->silent, ...$extra])['message_id'] ?? 0);
    }

    /** @param list<int> $messageIds */
    private function delete(int $chatId, array $messageIds): void
    {
        try {
            foreach (array_chunk($messageIds, 100) as $ids) {
                $this->telegram->call('deleteMessages', ['chat_id' => $chatId, 'message_ids' => $ids]);
            }
        } catch (TelegramException) {
            // Cleanup only: the message may be already deleted or too old.
        }
    }

    private function chat(int $chatId): Chat
    {
        $chat = Chat::query()->firstOrCreate(['telegram_id' => $chatId], ['type' => 'private', 'active' => true]);

        // Only a block makes a chat inactive, and a blocked bot gets no updates.
        if (! $chat->active) {
            $chat->update(['active' => true]);
        }

        return $chat;
    }

    private function answer(string $id, ?string $text = null, bool $alert = false): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert]));
        } catch (TelegramException) {
            // The query may have expired; the action itself still matters.
        }
    }

    /**
     * Excess actions are dropped before any other work; the user is warned once per window.
     *
     * @param  array<string, mixed>|null  $query
     */
    private function allowed(int $chatId, ?array $query): bool
    {
        $limit = (int) config('telegram.rate_limit.actions');
        $count = $this->limiter->hit($chatId);

        if ($count === $limit + 1) {
            $this->status->flood($chatId);
            $text = $this->messages->tooManyActions();

            if ($query === null) {
                $this->telegram->send($chatId, $text, ['disable_notification' => true]);
            } else {
                $this->answer($query['id'], $text, true);
            }
        }

        return $count <= $limit;
    }

    /**
     * Messages with the same result belong to the same series.
     *
     * @param  array<string, mixed>  $message
     * @return array{string, int} series key and pause
     */
    private function messageSeries(array $message): array
    {
        $text = trim($message['text']);
        $screen = preg_match('/^\/start(\s|$)/', $text) ? 'start' : Keyboards::screen($text, (int) $message['chat']['id']);

        // Different answers about an address are different actions.
        if ($screen === null && Chat::query()->where('telegram_id', (int) $message['chat']['id'])->whereNotNull('address_draft')->exists()) {
            $screen = 'address:'.md5($text);
        }

        return ['message:'.($screen ?? 'schedule'), (int) config('telegram.repeat_pause.screen')];
    }

    /**
     * A button that switches a setting becomes the opposite one, still in the same series.
     *
     * @param  array<string, mixed>  $query
     * @return array{string, int} series key and pause
     */
    private function querySeries(array $query): array
    {
        $data = (string) ($query['data'] ?? '');

        if ($data === 'mute' || $data === 'sound') {
            return ['sound', (int) config('telegram.repeat_pause.choice')];
        }
        if (preg_match('/^q[+-]?:(.*)$/', $data, $match)) {
            return ["queue:{$match[1]}", (int) config('telegram.repeat_pause.choice')];
        }

        return ["{$query['message']['message_id']}:{$data}", (int) config('telegram.repeat_pause.screen')];
    }

    /** There is delayed work to do by tick(). */
    public function isWaiting(): bool
    {
        return $this->delayed !== [] || $this->series->hasMessages();
    }

    /** Does delayed work; called regularly. */
    public function tick(bool $last = false): void
    {
        foreach ($this->series->takeMessages($last) as $chatId => $messageIds) {
            $this->delete($chatId, $messageIds);
        }

        foreach ($last ? [] : $this->delayed as $chatId => $reply) {
            if ($reply['due'] > now()->getTimestamp()) {
                continue;
            }

            try {
                $this->present($this->chat($chatId), $reply['screen'], $reply['message']);
            } catch (Throwable $e) {
                unset($this->delayed[$chatId]);
                Log::error("Delayed reply failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Shows a screen now or, when Telegram asks to wait, later without holding up other users.
     * Only the screen is repeated, never the action that led to it.
     */
    private function present(Chat $chat, string $screen, ?int $messageId = null, ?int $pressedId = null): void
    {
        $now = now()->getTimestamp();
        $waiting = $this->delayed[$chat->telegram_id] ?? null;

        // The newest screen replaces the one that still waits.
        if ($waiting !== null && $waiting['due'] > $now) {
            $this->delay($chat, $screen, $messageId, $pressedId, $waiting['due'], $now);

            return;
        }

        unset($this->delayed[$chat->telegram_id]);

        try {
            if ($screen === 'start') {
                $this->welcome($chat);
            } elseif ($messageId === null) {
                $this->updateKeyboard($chat, $screen);
                $this->open($chat, $screen, $pressedId);
            } else {
                $shown = $this->show($chat, $screen, $messageId);
                $kept = $this->kept($chat, $screen);

                if ($kept && $chat->menu_message_id === $messageId) {
                    // The service screen has turned into a kept one, which must stay.
                    $chat->update(['menu_message_id' => null]);
                } elseif (! $kept && $screen === 'address' && $chat->menu_message_id !== $messageId) {
                    // A kept list has lost its last address and is a service screen again.
                    $previous = $chat->menu_message_id;
                    $chat->update(['menu_message_id' => $shown]);
                    $this->delete($chat->telegram_id, array_values(array_filter([$previous])));
                }
            }
        } catch (TelegramException $e) {
            if ($e->getCode() !== 429) {
                throw $e;
            }

            $this->delay($chat, $screen, $messageId, $pressedId, $now + max(1, $e->retryAfter()), $waiting === null ? $now : $waiting['until'] - $this->replyExpires());
        }
    }

    /** @param int $since when the reply was asked for */
    private function delay(Chat $chat, string $screen, ?int $messageId, ?int $pressedId, int $due, int $since): void
    {
        if ($pressedId !== null) {
            $this->series->collect($chat->telegram_id, $pressedId);
        }

        $until = $since + $this->replyExpires();

        if ($due > $until) {
            unset($this->delayed[$chat->telegram_id]);
            Log::warning('A reply was dropped: Telegram asks to wait for '.($due - now()->getTimestamp()).' s');

            return;
        }

        $this->delayed[$chat->telegram_id] = ['screen' => $screen, 'message' => $messageId, 'due' => $due, 'until' => $until];
    }

    private function replyExpires(): int
    {
        return (int) config('telegram.reply_expires');
    }

    private function showAddresses(Chat $chat, ?int $messageId = null): int
    {
        $addresses = $chat->addresses()->with(['street', 'city', 'events'])->get()->all();

        return $this->render($chat, $this->messages->addresses($addresses), Keyboards::addresses($addresses), $messageId);
    }

    private function showAddressDraft(Chat $chat, ?int $messageId = null): int
    {
        $draft = $chat->address_draft;

        // Finished or cancelled meanwhile.
        if ($draft === null || ! $this->addresses->isWaiting($chat)) {
            return $this->showAddresses($chat, $messageId);
        }

        return $this->render(
            $chat,
            $this->messages->addressDraft($draft['step'], $draft['problem'] ?? null, $this->addresses->street($chat)),
            Keyboards::addressDraft($draft['step'], $this->addresses->options($chat), $this->cities),
            $messageId,
        );
    }

    /** Screens that stay in the chat instead of being replaced by the next one. */
    private function kept(Chat $chat, string $screen): bool
    {
        return match ($screen) {
            'schedule' => $chat->subscriptions()->exists(),
            // A finished draft is shown as the list.
            'address', 'address_draft' => ($screen === 'address' || ! $this->addresses->isWaiting($chat)) && $chat->addresses()->exists(),
            default => false,
        };
    }
}
