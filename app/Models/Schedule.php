<?php

namespace App\Models;

use App\Schedule\DaySchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Current schedule of a date. */
class Schedule extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['queues' => 'array', 'declared_at' => 'immutable_datetime'];

    /** @return BelongsTo<PublicationRecord, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(PublicationRecord::class);
    }

    public function day(): DaySchedule
    {
        return DaySchedule::fromArray($this->queues);
    }
}
