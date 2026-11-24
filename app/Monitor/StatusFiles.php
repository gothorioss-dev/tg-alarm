<?php

namespace App\Monitor;

/** Small JSON files shared by processes; they work even when the database does not. */
final class StatusFiles
{
    public function enabled(): bool
    {
        return (string) config('monitor.path') !== '';
    }

    /** @return array<string, mixed> */
    public function read(string $name): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($this->file($name)), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    public function write(string $name, array $data): void
    {
        if (! $this->enabled()) {
            return;
        }

        $directory = (string) config('monitor.path');
        $temporary = $this->file($name).'.tmp';

        // A failure, such as a full disk, must not stop the process itself.
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $json = json_encode($data);

        if (@file_put_contents($temporary, $json) === strlen($json)) {
            @rename($temporary, $this->file($name));
        }
    }

    private function file(string $name): string
    {
        return config('monitor.path')."/{$name}.json";
    }
}
