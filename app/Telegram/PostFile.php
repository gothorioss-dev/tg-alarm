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

        $message = $this->telegram->call('sendDocument', [
            'chat_id' => $chatId,
            'document' => "https://t.me/{$channel}/{$messageId}",
            'disable_notification' => true,
        ]);

        try {
            $document = $message['document'] ?? null;

            if ($document === null || ($document['file_name'] ?? '') !== $name) {
                throw new SourceException("Post {$messageId} has another file: ".($document['file_name'] ?? 'none'));
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

    private function delete(int $chatId, int $messageId): void
    {
        try {
            $this->telegram->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (TelegramException) {
            // Only tidiness: the file has been received anyway.
        }
    }
}
