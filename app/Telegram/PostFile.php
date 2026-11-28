<?php

namespace App\Telegram;

use App\Sources\SourceException;

/**
 * Gets a file of a post of a public channel. A bot cannot read the channel, but Telegram lets it
 * send a file by the link to the post; the message to the admin is deleted at once.
 */
final class PostFile
{
    public function __construct(private TelegramClient $telegram) {}

    /**
     * @return string path of a temporary file, which the caller deletes
     *
     * @throws SourceException
     * @throws TelegramException
     */
    public function download(string $channel, int $messageId, string $name, int $maxSize): string
    {
        $chatId = (int) config('telegram.admin_id');

        if ($chatId === 0) {
            throw new SourceException('TELEGRAM_ADMIN_ID is needed to get files');
        }

        $message = $this->send($chatId, "https://t.me/{$channel}/{$messageId}");

        // The old file of an edited post: another link is fetched anew.
        if (($message['document']['file_name'] ?? '') !== $name) {
            $this->delete($chatId, (int) $message['message_id']);
            $message = $this->send($chatId, "https://t.me/{$channel}/{$messageId}?single");
        }

        try {
            $document = $message['document'] ?? null;

            if ($document === null || ($document['file_name'] ?? '') !== $name) {
                throw new OtherFileException("Post {$messageId} has another file: ".($document['file_name'] ?? 'none'));
            }
            if (($document['file_size'] ?? 0) > $maxSize) {
                throw new SourceException("File of post {$messageId} is too big");
            }

            $content = $this->telegram->download($this->telegram->call('getFile', ['file_id' => $document['file_id']])['file_path']);
        } finally {
            $this->delete($chatId, (int) $message['message_id']);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'post');
        file_put_contents($path, $content);

        return $path;
    }

    /** @return array<string, mixed> */
    private function send(int $chatId, string $link): array
    {
        return $this->telegram->call('sendDocument', ['chat_id' => $chatId, 'document' => $link, 'disable_notification' => true]);
    }

    private function delete(int $chatId, int $messageId): void
    {
        try {
            $this->telegram->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (TelegramException) {
            // Only tidiness: the file has been received anyway.
        }
    }
}
