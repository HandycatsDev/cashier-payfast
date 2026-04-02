<?php

namespace HandycatsDev\CashierPayFast\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?Model $billable,
        public array $payload
    ) {
    }
}
