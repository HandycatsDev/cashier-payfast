<?php

namespace HandycatsDev\CashierPayFast;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use HandycatsDev\CashierPayFast\Concerns\Prorates;

class Subscription extends Model
{
    use Prorates;

    const STATUS_ACTIVE = 'active';
    const STATUS_TRIALING = 'trialing';
    const STATUS_PAST_DUE = 'past_due';
    const STATUS_PAUSED = 'paused';
    const STATUS_CANCELED = 'canceled';

    const FREQUENCY_MONTHLY = 3;
    const FREQUENCY_QUARTERLY = 4;
    const FREQUENCY_BIANNUALLY = 5;
    const FREQUENCY_ANNUALLY = 6;

    const DEFAULT_TYPE = 'default';

    protected $guarded = [];

    protected $with = ['items'];

    protected $casts = [
        'frequency' => 'integer',
        'cycles' => 'integer',
        'trial_ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function billable()
    {
        return $this->morphTo();
    }

    public function items()
    {
        return $this->hasMany(Cashier::$subscriptionItemModel);
    }

    public function findItemOrFail($price)
    {
        return $this->items()->where('price_id', $price)->firstOrFail();
    }

    public function transactions()
    {
        return $this->hasMany(Cashier::$transactionModel, 'provider_subscription_id', 'provider_id')
            ->orderByDesc('created_at');
    }

    public function hasMultiplePrices(): bool
    {
        return $this->items->count() > 1;
    }

    public function hasSinglePrice(): bool
    {
        return ! $this->hasMultiplePrices();
    }

    public function hasProduct($product): bool
    {
        return $this->items->contains(fn (SubscriptionItem $item) => $item->product_id === $product);
    }

    public function hasPrice($price): bool
    {
        return $this->items->contains(fn (SubscriptionItem $item) => $item->price_id === $price);
    }

    public function valid(): bool
    {
        return $this->onTrial() || $this->active() || (! Cashier::$deactivatePastDue && $this->pastDue());
    }

    public function scopeValid($query)
    {
        $query->where('status', self::STATUS_TRIALING)
            ->orWhere('status', self::STATUS_ACTIVE);

        if (! Cashier::$deactivatePastDue) {
            $query->orWhere('status', self::STATUS_PAST_DUE);
        }
    }

    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING;
    }

    public function scopeOnTrial($query)
    {
        $query->where('status', self::STATUS_TRIALING);
    }

    public function hasExpiredTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function scopeExpiredTrial($query)
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', Carbon::now());
    }

    public function scopeNotOnTrial($query)
    {
        $query->where('status', '!=', self::STATUS_TRIALING);
    }

    public function active(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive($query)
    {
        $query->where('status', '=', self::STATUS_ACTIVE);
    }

    public function recurring(): bool
    {
        return $this->active() && ! $this->onPausedGracePeriod() && ! $this->onGracePeriod();
    }

    public function scopeRecurring($query)
    {
        $query->active()->notOnPausedGracePeriod()->notOnGracePeriod();
    }

    public function pastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    public function scopePastDue($query)
    {
        $query->where('status', self::STATUS_PAST_DUE);
    }

    public function paused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function scopePaused($query)
    {
        $query->where('status', self::STATUS_PAUSED);
    }

    public function scopeNotPaused($query)
    {
        $query->where('status', '!=', self::STATUS_PAUSED);
    }

    public function onPausedGracePeriod(): bool
    {
        return $this->paused_at && $this->paused_at->isFuture();
    }

    public function scopeOnPausedGracePeriod($query)
    {
        $query->whereNotNull('paused_at')->where('paused_at', '>', Carbon::now());
    }

    public function scopeNotOnPausedGracePeriod($query)
    {
        $query->whereNull('paused_at')->orWhere('paused_at', '<=', Carbon::now());
    }

    public function canceled(): bool
    {
        return $this->status === self::STATUS_CANCELED;
    }

    public function scopeCanceled($query)
    {
        $query->where('status', self::STATUS_CANCELED);
    }

    public function scopeNotCanceled($query)
    {
        $query->where('status', '!=', self::STATUS_CANCELED);
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    public function scopeOnGracePeriod($query)
    {
        $query->whereNotNull('ends_at')->where('ends_at', '>', Carbon::now());
    }

    public function scopeNotOnGracePeriod($query)
    {
        $query->whereNull('ends_at')->orWhere('ends_at', '<=', Carbon::now());
    }

    public function pause(): static
    {
        $client = app(PayFastClient::class);
        $client->pauseSubscription($this->provider_id);

        $this->forceFill([
            'status' => self::STATUS_PAUSED,
            'paused_at' => now(),
        ])->save();

        return $this;
    }

    public function resume(): static
    {
        $client = app(PayFastClient::class);
        $client->unpauseSubscription($this->provider_id);

        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'paused_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Cancel the subscription with PayFast and mark it locally as cancelled.
     *
     * The $endsAt parameter lets the caller specify when the grace period
     * ends — typically the end of the current billing period that the
     * customer has already paid for. When null, defaults to now() (no grace
     * period), which is the legacy behaviour.
     *
     * Implementation note: the local state is set BEFORE the PayFast API
     * call. This is intentional to defeat a race where PayFast's CANCELLED
     * ITN can arrive at our webhook *before* the cancel() HTTP call returns
     * to PHP. If we set local state after the API call, the webhook sees
     * `ends_at IS NULL`, sets it to now(), and triggers immediate free-plan
     * assignment — silently throwing away the customer's grace period.
     * By writing the future ends_at first, the webhook sees a non-null
     * value and leaves it alone.
     *
     * On API failure we roll the local state back so the caller can retry.
     */
    public function cancel(?CarbonInterface $endsAt = null): static
    {
        $client = app(PayFastClient::class);

        $originalStatus = $this->status;
        $originalEndsAt = $this->ends_at;

        $this->forceFill([
            'status'  => self::STATUS_CANCELED,
            'ends_at' => $endsAt ?? now(),
        ])->save();

        try {
            $client->cancelSubscription($this->provider_id);
        } catch (\Throwable $e) {
            $this->forceFill([
                'status'  => $originalStatus,
                'ends_at' => $originalEndsAt,
            ])->save();

            throw $e;
        }

        return $this;
    }

    public function lastPayment(): ?Payment
    {
        if ($transaction = $this->transactions()->orderByDesc('billed_at')->first()) {
            return new Payment($transaction->amount_gross, $transaction->currency, $transaction->billed_at);
        }

        return null;
    }
}
