<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CityEventRow extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
}
