<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Database\Eloquent\Model;
use Money\Currency;

class Transaction extends Model
{
    const STATUS_COMPLETE = 'COMPLETE';
    const STATUS_FAILED = 'FAILED';
    const STATUS_PENDING = 'PENDING';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $guarded = [];

    protected $casts = [
        'amount_gross' => 'decimal:2',
        'amount_fee' => 'decimal:2',
        'amount_net' => 'decimal:2',
        'billed_at' => 'datetime',
    ];

    public function billable()
    {
        return $this->morphTo();
    }

    public function subscription()
    {
        return $this->belongsTo(Cashier::$subscriptionModel, 'provider_subscription_id', 'provider_id');
    }

    public function total(): string
    {
        return Cashier::formatAmount((int) ($this->amount_gross * 100), $this->currency());
    }

    public function fee(): string
    {
        return Cashier::formatAmount((int) ($this->amount_fee * 100), $this->currency());
    }

    public function net(): string
    {
        return Cashier::formatAmount((int) ($this->amount_net * 100), $this->currency());
    }

    public function currency(): Currency
    {
        return new Currency($this->currency ?? 'ZAR');
    }
}
