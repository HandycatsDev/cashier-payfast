<?php

namespace HandycatsDev\CashierPayFast\Concerns;

use HandycatsDev\CashierPayFast\Cashier;
use HandycatsDev\CashierPayFast\Subscription;
use HandycatsDev\CashierPayFast\SubscriptionBuilder;

trait ManagesSubscriptions
{
    public function subscriptions()
    {
        return $this->morphMany(Cashier::$subscriptionModel, 'billable')->orderByDesc('created_at');
    }

    public function subscription($type = 'default')
    {
        return $this->subscriptions->where('type', $type)->first();
    }

    public function newSubscription(int $amount, string $name, string $type = Subscription::DEFAULT_TYPE): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $amount, $name, $type);
    }

    public function onTrial($type = 'default', $price = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->onTrial()) {
            return false;
        }

        return $price ? $subscription->hasPrice($price) : true;
    }

    public function hasExpiredTrial($type = 'default', $price = null): bool
    {
        if (func_num_args() === 0 && $this->hasExpiredGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->hasExpiredTrial()) {
            return false;
        }

        return $price ? $subscription->hasPrice($price) : true;
    }

    public function onGenericTrial(): bool
    {
        if (is_null($this->customer)) {
            return false;
        }

        return $this->customer->onGenericTrial();
    }

    public function hasExpiredGenericTrial(): bool
    {
        if (is_null($this->customer)) {
            return false;
        }

        return $this->customer->hasExpiredGenericTrial();
    }

    public function trialEndsAt($type = 'default')
    {
        if (is_null($this->customer)) {
            return null;
        }

        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return $this->customer->trial_ends_at;
        }

        if ($subscription = $this->subscription($type)) {
            return $subscription->trial_ends_at;
        }

        return $this->customer->trial_ends_at;
    }

    public function subscribed($type = 'default', $price = null): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $price ? $subscription->hasPrice($price) : true;
    }

    public function subscribedToProduct($products, $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $products as $product) {
            if ($subscription->hasProduct($product)) {
                return true;
            }
        }

        return false;
    }

    public function subscribedToPrice($prices, $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $prices as $price) {
            if ($subscription->hasPrice($price)) {
                return true;
            }
        }

        return false;
    }

    public function onProduct($product): bool
    {
        return ! is_null($this->subscriptions->first(function (Subscription $subscription) use ($product) {
            return $subscription->valid() && $subscription->hasProduct($product);
        }));
    }

    public function onPrice($price): bool
    {
        return ! is_null($this->subscriptions->first(function (Subscription $subscription) use ($price) {
            return $subscription->valid() && $subscription->hasPrice($price);
        }));
    }
}
