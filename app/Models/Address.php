<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Address extends Model
{
    protected $guarded = [];

    protected $casts = ['house_known' => 'boolean'];

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Street, $this> */
    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    /** @return HasMany<AddressEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AddressEvent::class)->orderBy('starts_at');
    }
}
