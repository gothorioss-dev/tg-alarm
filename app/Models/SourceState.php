<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceState extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'source';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['failures' => 0, 'initialized' => false];

    protected $casts = [
        'state' => 'array',
        'initialized' => 'boolean',
        'polled_at' => 'immutable_datetime',
        'succeeded_at' => 'immutable_datetime',
    ];
}
