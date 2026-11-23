<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A stored version of a source publication. */
class PublicationRecord extends Model
{
    public const APPLIED = 'applied';

    public const CONFIRMED = 'confirmed';

    public const IGNORED = 'ignored';

    public const BASELINE = 'baseline';

    public const REJECTED = 'rejected';

    public $timestamps = false;

    protected $table = 'publications';

    protected $guarded = [];

    protected $casts = [
        'queues' => 'array',
        'declared_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'fetched_at' => 'immutable_datetime',
    ];
}
