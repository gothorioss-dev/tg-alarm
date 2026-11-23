<?php

namespace App\Sources;

use App\Schedule\Kind;

final class SourceRegistry
{
    /** @return list<string> enabled source keys */
    public function keys(): array
    {
        return array_keys(array_filter(config('sources.sources'), fn (array $s) => $s['enabled']));
    }

    public function make(string $key): Source
    {
        return app()->make($this->config($key, 'class'), $this->config($key, 'options', []));
    }

    public function priority(string $key): int
    {
        return (int) $this->config($key, 'priority', 0);
    }

    public function interval(string $key): int
    {
        return (int) $this->config($key, 'interval', 60);
    }

    public function label(string $key): string
    {
        return (string) $this->config($key, 'label', $key);
    }

    public function provides(string $key, Kind $kind): bool
    {
        return in_array($kind->value, $this->config($key, 'kinds', []), true);
    }

    private function config(string $key, string $name, mixed $default = null): mixed
    {
        return config("sources.sources.{$key}.{$name}", $default);
    }
}
