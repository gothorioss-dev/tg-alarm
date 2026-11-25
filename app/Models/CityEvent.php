<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An outage published by a city source, with all its streets. */
class CityEvent extends Model
{
    protected $guarded = [];

    protected $attributes = ['announced' => false];

    protected $casts = ['announced' => 'boolean', 'ends_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];

    /** @return HasMany<CityEventRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(CityEventRow::class);
    }
}
