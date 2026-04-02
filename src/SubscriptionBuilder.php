<?php

namespace HandycatsDev\CashierPayFast;

class SubscriptionBuilder
{
    protected int $quantity = 1;

    protected int $frequency = Subscription::FREQUENCY_MONTHLY;

    protected int $cycles = 0;

    public function __construct(
        protected $billable,
        protected int $amount,
        protected string $name,
        protected string $type = Subscription::DEFAULT_TYPE
    ) {
    }

    public function quantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function monthly(): static
    {
        $this->frequency = Subscription::FREQUENCY_MONTHLY;

        return $this;
    }

    public function quarterly(): static
    {
        $this->frequency = Subscription::FREQUENCY_QUARTERLY;

        return $this;
    }

    public function biannually(): static
    {
        $this->frequency = Subscription::FREQUENCY_BIANNUALLY;

        return $this;
    }

    public function yearly(): static
    {
        $this->frequency = Subscription::FREQUENCY_ANNUALLY;

        return $this;
    }

    public function cycles(int $cycles): static
    {
        $this->cycles = $cycles;

        return $this;
    }

    public function checkout(array $options = []): Checkout
    {
        $params = array_merge([
            'amount' => number_format($this->amount / 100, 2, '.', ''),
            'item_name' => $this->name,
            'subscription_type' => 1,
            'frequency' => $this->frequency,
            'cycles' => $this->cycles,
        ], $options);

        return Checkout::make($params)->customData([
            'subscription_type' => $this->type,
        ]);
    }
}
