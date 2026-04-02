<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Database\Eloquent\Model;

class SubscriptionItem extends Model
{
    protected $guarded = [];

    public function subscription()
    {
        return $this->belongsTo(Cashier::$subscriptionModel);
    }
}
