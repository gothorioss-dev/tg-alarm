<?php

namespace App\Address;

use App\Models\Street;
use App\Sources\SourceException;
use Illuminate\Support\Facades\Log;

/** Houses known to the source; the list is incomplete. */
final class HouseDirectory
{
    public function __construct(private CityRegistry $registry) {}

    /** @return bool|null null when the list is not available */
    public function knows(Street $street, string $house): ?bool
    {
        $houses = $this->houses($street);

        return $houses === null || $houses === [] ? null : (new HouseList(implode('|', $houses)))->match($house) !== null;
    }

    /** @return list<string>|null */
    private function houses(Street $street): ?array
    {
        if ($street->houses_at !== null && $street->houses_at->addDays((int) config('addresses.houses_keep_days'))->isFuture()) {
            return $street->houses;
        }

        try {
            $houses = $this->registry->directory($street->city->code)->houses($street->name);
            $street->update(['houses' => $houses, 'houses_at' => now()]);

            return $houses;
        } catch (SourceException $e) {
            Log::warning("Houses are not available: {$e->getMessage()}");

            return $street->houses;
        }
    }
}
