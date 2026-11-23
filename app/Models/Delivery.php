<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = [];

    protected $casts = [
        'available_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
    ];
}
