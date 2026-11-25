<?php

namespace App\Address;

use App\Sources\SourceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Calls the public functions of a Google Apps Script page, as the page itself does. */
final class GoogleScriptDirectory implements StreetDirectory
{
    private const STREETS_TIMEOUT = 20;

    /** The user waits for the houses, and so do other users. */
    private const HOUSES_TIMEOUT = 5;

    public function __construct(private string $url) {}

    public function streets(): array
    {
        return $this->strings($this->call('getStreetList', [], self::STREETS_TIMEOUT));
    }

    public function houses(string $street): array
    {
        return $this->strings($this->call('getBuildingsForStreet', [$street], self::HOUSES_TIMEOUT));
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function strings(array $items): array
    {
        return array_values(array_map(strval(...), array_filter($items, fn (mixed $item) => is_scalar($item) && trim((string) $item) !== '')));
    }

    /**
     * @param  list<string>  $arguments
     * @return list<mixed>
     */
    private function call(string $function, array $arguments, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->connectTimeout(min($timeout, 10))->withUserAgent('Mozilla/5.0')
                ->withHeaders(['X-Same-Domain' => '1'])->asForm()
                ->post($this->url.'?nocache_id='.random_int(0, 99999), [
                    'request' => json_encode([$function, json_encode($arguments, JSON_UNESCAPED_UNICODE), null, [0], null, null, true, 0], JSON_UNESCAPED_UNICODE),
                ]);
        } catch (ConnectionException $e) {
            throw new SourceException($e->getMessage(), previous: $e);
        }

        if ($response->status() !== 200) {
            throw new SourceException("HTTP {$response->status()}");
        }

        // The answer has a prefix that keeps it from being run as a script.
        $parts = json_decode((string) preg_replace('/^\)\]\}\'\s*/', '', $response->body()), true);

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (is_array($part) && ($part[0] ?? null) === 'op.exec' && ($part[1][0] ?? null) === 0 && is_string($part[1][1] ?? null)) {
                $result = json_decode($part[1][1], true);

                if (is_array($result) && array_is_list($result)) {
                    return $result;
                }
            }
        }

        throw new SourceException("Response of {$function} is not recognized");
    }
}
