<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $guarded = [];

    protected $attributes = ['silent' => false];

    protected $casts = ['active' => 'boolean', 'silent' => 'boolean', 'telegram_id' => 'integer', 'remind_minutes' => 'integer', 'menu_message_id' => 'integer', 'address_draft' => 'array'];

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderBy('queue');
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderBy('id');
    }
}
