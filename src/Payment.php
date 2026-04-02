<?php

namespace HandycatsDev\CashierPayFast;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use Money\Currency;

class Payment implements Arrayable, Jsonable, JsonSerializable
{
    public function __construct(
        public $amount,
        public $currency,
        public $date
    ) {
    }

    public function amount(): string
    {
        return Cashier::formatAmount((int) ($this->amount * 100), $this->currency);
    }

    public function rawAmount()
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return new Currency($this->currency);
    }

    public function date(): Carbon
    {
        return $this->date;
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount(),
            'currency' => $this->currency,
            'date' => $this->date()->toIso8601String(),
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
