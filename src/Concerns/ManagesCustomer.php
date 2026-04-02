<?php

namespace HandycatsDev\CashierPayFast\Concerns;

use HandycatsDev\CashierPayFast\Cashier;

trait ManagesCustomer
{
    public function createAsCustomer(array $options = [])
    {
        if ($customer = $this->customer) {
            return $customer;
        }

        if (! array_key_exists('name', $options) && $name = $this->providerName()) {
            $options['name'] = $name;
        }

        if (! array_key_exists('email', $options) && $email = $this->providerEmail()) {
            $options['email'] = $email;
        }

        $trialEndsAt = $options['trial_ends_at'] ?? null;
        unset($options['trial_ends_at']);

        $customer = $this->customer()->make();
        $customer->provider_id = $options['provider_id'] ?? '';
        $customer->name = $options['name'] ?? '';
        $customer->email = $options['email'] ?? '';
        $customer->trial_ends_at = $trialEndsAt;
        $customer->save();

        $this->refresh();

        return $customer;
    }

    public function customer()
    {
        return $this->morphOne(Cashier::$customerModel, 'billable');
    }

    public function providerName(): ?string
    {
        return $this->name;
    }

    public function providerEmail(): ?string
    {
        return $this->email;
    }
}
