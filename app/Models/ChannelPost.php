<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelPost extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = ['silent' => false, 'attempts' => 0];

    protected $casts = [
        'message_id' => 'integer',
        'reply_to' => 'integer',
        'silent' => 'boolean',
        'attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<CityEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CityEvent::class, 'city_event_id');
    }
}
