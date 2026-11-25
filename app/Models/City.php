<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $guarded = [];

    protected $attributes = ['failures' => 0, 'shrunk' => 0];

    protected $casts = [
        'failures' => 'integer',
        'shrunk' => 'integer',
        'polled_at' => 'immutable_datetime',
        'succeeded_at' => 'immutable_datetime',
        'streets_polled_at' => 'immutable_datetime',
    ];
}
