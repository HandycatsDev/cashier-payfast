<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

    public function billable()
    {
        return $this->morphTo();
    }

    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function hasExpiredGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }
}
