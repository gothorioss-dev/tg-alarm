<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'telegram_id' => 'integer'];

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderBy('queue');
    }

    public function isPrivate(): bool
    {
        return $this->type === 'private';
    }
}
