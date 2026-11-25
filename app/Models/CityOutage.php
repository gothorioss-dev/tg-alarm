<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A row of the last response of a city source. */
class CityOutage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
}
