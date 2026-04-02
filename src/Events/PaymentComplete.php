<?php

namespace HandycatsDev\CashierPayFast\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use HandycatsDev\CashierPayFast\Transaction;

class PaymentComplete
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Model $billable,
        public Transaction $transaction,
        public array $payload
    ) {
    }
}
