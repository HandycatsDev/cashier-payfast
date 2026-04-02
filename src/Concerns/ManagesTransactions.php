<?php

namespace HandycatsDev\CashierPayFast\Concerns;

use HandycatsDev\CashierPayFast\Cashier;

trait ManagesTransactions
{
    public function transactions()
    {
        return $this->morphMany(Cashier::$transactionModel, 'billable')->orderByDesc('billed_at');
    }
}
