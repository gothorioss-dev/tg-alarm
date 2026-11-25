<?php

namespace App\Telegram;

use App\Address\AddressText;
use App\Address\AddressTracker;
use App\Address\CityRegistry;
use App\Address\HouseDirectory;
use App\Address\StreetSearch;
use App\Models\Address;
use App\Models\Chat;
use App\Models\City;
use App\Models\Street;

/** Adds and removes addresses; an unfinished answer is kept in the chat. */
final class AddressDialog
{
    private const MAX_OPTIONS = 8;

    private const MAX_HOUSE = 30;

    public function __construct(private CityRegistry $registry, private StreetSearch $search, private HouseDirectory $houses, private AddressTracker $tracker) {}

    public function isWaiting(Chat $chat): bool
    {
        $draft = $chat->address_draft;

        return $draft !== null && now()->getTimestamp() - $draft['at'] < 60 * (int) config('addresses.draft_minutes');
    }

    public function cancel(Chat $chat): void
    {
        if ($chat->address_draft !== null) {
            $chat->update(['address_draft' => null]);
        }
    }

    /** @return string|null a problem */
    public function begin(Chat $chat, ?string $code = null): ?string
    {
        if ($chat->addresses()->count() >= (int) config('addresses.max_addresses')) {
            return 'limit';
        }

        $codes = $this->registry->codes();
        $code ??= count($codes) === 1 ? $codes[0] : null;

        if ($code !== null && ! in_array($code, $codes, true)) {
            return null;
        }

        $this->draft($chat, $code === null
            ? ['step' => 'city']
            : ['step' => 'street', 'city' => City::query()->firstOrCreate(['code' => $code])->id]);

        return null;
    }

    /** @return bool whether the address has been added */
    public function text(Chat $chat, string $text): bool
    {
        $draft = $chat->address_draft;
        $city = City::query()->find($draft['city'] ?? 0);

        if ($city === null) {
            return false;
        }
        if ($draft['step'] === 'house' && preg_match('/\d/', $text) && ($street = Street::query()->find($draft['street'])) !== null) {
            return $this->add($chat, $street, $text);
        }

        $text = mb_substr($text, 0, 120);
        $name = preg_quote(mb_strtolower($this->registry->name($city->code)), '/');
        $text = (string) preg_replace("/^(?:м\.|місто|город)?\s*{$name}\s*[,.]\s*/ui", '', trim($text));

        // The name itself may end with a number.
        [$streets, $exact] = $this->search->find($city, $text);
        $house = null;

        if (! $exact) {
            [$name, $house] = AddressText::split($text);

            if ($house !== null) {
                [$streets, $exact] = $this->search->find($city, $name);
            }
        }

        if ($exact && count($streets) === 1) {
            return $this->choose($chat, $streets[0], $house);
        }

        $base = ['city' => $city->id, 'house' => $house];
        $this->draft($chat, match (true) {
            $streets === [] => [...$base, 'step' => 'street', 'problem' => Street::query()->where('city_id', $city->id)->exists() ? 'unknown' : 'loading'],
            count($streets) > self::MAX_OPTIONS => [...$base, 'step' => 'street', 'problem' => 'many'],
            default => [...$base, 'step' => 'choose', 'options' => array_map(fn (Street $street) => $street->id, $streets)],
        });

        return false;
    }

    /** @return bool whether the address has been added */
    public function chooseStreet(Chat $chat, int $streetId): bool
    {
        $draft = $chat->address_draft;

        if (! $this->isWaiting($chat) || ! in_array($streetId, $draft['options'] ?? [], true) || ($street = Street::query()->find($streetId)) === null) {
            return false;
        }

        return $this->choose($chat, $street, $draft['house'] ?? null);
    }

    public function remove(Chat $chat, int $addressId): bool
    {
        return $chat->addresses()->whereKey($addressId)->delete() > 0;
    }

    /** @return list<Street> streets offered to choose from */
    public function options(Chat $chat): array
    {
        $ids = $chat->address_draft['options'] ?? [];
        $streets = Street::query()->whereKey($ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id) => $streets->get($id), $ids)));
    }

    public function street(Chat $chat): ?Street
    {
        return Street::query()->find($chat->address_draft['street'] ?? 0);
    }

    private function choose(Chat $chat, Street $street, ?string $house): bool
    {
        if ($house !== null) {
            return $this->add($chat, $street, $house);
        }

        $this->draft($chat, ['step' => 'house', 'city' => $street->city_id, 'street' => $street->id]);

        return false;
    }

    private function add(Chat $chat, Street $street, string $text): bool
    {
        $house = AddressText::house($text);

        if ($house === '' || mb_strlen($house) > self::MAX_HOUSE) {
            $this->draft($chat, ['step' => 'house', 'city' => $street->city_id, 'street' => $street->id, 'problem' => 'house']);

            return false;
        }
        // The limit also holds for answers to old messages.
        if ($chat->addresses()->count() >= (int) config('addresses.max_addresses')) {
            $this->cancel($chat);

            return true;
        }

        $address = Address::query()->firstOrCreate(
            ['chat_id' => $chat->id, 'street_id' => $street->id, 'house_key' => $house],
            ['city_id' => $street->city_id, 'house' => $house, 'house_known' => $this->houses->knows($street, $house)],
        );

        if ($address->wasRecentlyCreated) {
            $this->tracker->start($address);
        }

        $this->cancel($chat);

        return true;
    }

    /** @param array<string, mixed> $draft */
    private function draft(Chat $chat, array $draft): void
    {
        $chat->update(['address_draft' => [...$draft, 'at' => now()->getTimestamp()]]);
    }
}
