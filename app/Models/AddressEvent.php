<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An outage at an address of a user. */
class AddressEvent extends Model
{
    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'announced' => false];

    protected $casts = [
        'revision' => 'integer',
        'announced' => 'boolean',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'timed_at' => 'immutable_datetime',
        'reminded_day_at' => 'immutable_datetime',
        'reminded_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Address, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /** @return BelongsTo<CityEvent, $this> */
    public function cityEvent(): BelongsTo
    {
        return $this->belongsTo(CityEvent::class);
    }
}
