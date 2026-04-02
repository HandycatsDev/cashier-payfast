<?php

namespace HandycatsDev\CashierPayFast\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use HandycatsDev\CashierPayFast\Transaction;

class TransactionUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * The billable entity.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    public $billable;

    /**
     * The transaction instance.
     *
     * @var \HandycatsDev\CashierPayFast\Transaction
     */
    public $transaction;

    /**
     * The webhook payload.
     *
     * @var array
     */
    public $payload;

    /**
     * Create a new event instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $billable
     * @param  \HandycatsDev\CashierPayFast\Transaction  $transaction
     * @param  array  $payload
     * @return void
     */
    public function __construct(Model $billable, Transaction $transaction, array $payload)
    {
        $this->billable = $billable;
        $this->transaction = $transaction;
        $this->payload = $payload;
    }
}
