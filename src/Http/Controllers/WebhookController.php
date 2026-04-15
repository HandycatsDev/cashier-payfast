<?php

namespace HandycatsDev\CashierPayFast\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use HandycatsDev\CashierPayFast\Cashier;
use HandycatsDev\CashierPayFast\Events\PaymentComplete;
use HandycatsDev\CashierPayFast\Events\PaymentFailed;
use HandycatsDev\CashierPayFast\Events\SubscriptionCanceled;
use HandycatsDev\CashierPayFast\Events\SubscriptionCreated;
use HandycatsDev\CashierPayFast\Events\WebhookHandled;
use HandycatsDev\CashierPayFast\Events\WebhookReceived;
use HandycatsDev\CashierPayFast\Http\Middleware\VerifyWebhookSignature;
use HandycatsDev\CashierPayFast\Subscription;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct()
    {
        if (config('cashier.passphrase')) {
            $this->middleware(VerifyWebhookSignature::class);
        }
    }

    public function __invoke(Request $request)
    {
        $payload = $request->all();

        WebhookReceived::dispatch($payload);

        $status = $payload['payment_status'] ?? null;

        match ($status) {
            'COMPLETE' => $this->handleComplete($payload),
            'FAILED' => $this->handleFailed($payload),
            'CANCELLED' => $this->handleCancelled($payload),
            default => null,
        };

        WebhookHandled::dispatch($payload);

        return new Response('OK', 200);
    }

    protected function handleComplete(array $payload): void
    {
        $billable = $this->findOrCreateBillable($payload);

        if (! $billable) {
            Log::warning('[CashierPayFast] Unable to resolve billable for COMPLETE ITN — no transaction recorded', [
                'pf_payment_id' => $payload['pf_payment_id'] ?? null,
                'token'         => $payload['token'] ?? null,
                'email_address' => $payload['email_address'] ?? null,
                'custom_str1'   => $payload['custom_str1'] ?? null,
            ]);

            return;
        }

        $transaction = $billable->transactions()->create([
            'provider_id' => $payload['pf_payment_id'],
            'provider_subscription_id' => $payload['token'] ?? null,
            'payment_status' => $payload['payment_status'],
            'amount_gross' => $payload['amount_gross'],
            'amount_fee' => $payload['amount_fee'],
            'amount_net' => $payload['amount_net'],
            'currency' => 'ZAR',
            'billed_at' => now(),
        ]);

        PaymentComplete::dispatch($billable, $transaction, $payload);

        if (! empty($payload['token'])) {
            $this->handleSubscriptionPayment($billable, $payload);
        }
    }

    protected function handleFailed(array $payload): void
    {
        $billable = $this->findOrCreateBillable($payload);

        PaymentFailed::dispatch($billable, $payload);
    }

    protected function handleCancelled(array $payload): void
    {
        if (empty($payload['token'])) {
            return;
        }

        $subscription = Cashier::$subscriptionModel::where('provider_id', $payload['token'])->first();

        if (! $subscription) {
            return;
        }

        // Only set ends_at if it is not already configured. A caller that
        // invoked $subscription->cancel($futureDate) has set a grace-period
        // expiry that we must preserve — otherwise the webhook would
        // silently terminate the grace period the moment PayFast
        // acknowledges the cancel.
        $attributes = ['status' => Subscription::STATUS_CANCELED];

        if ($subscription->ends_at === null) {
            $attributes['ends_at'] = now();
        }

        $subscription->forceFill($attributes)->save();

        SubscriptionCanceled::dispatch($subscription, $payload);
    }

    protected function handleSubscriptionPayment($billable, array $payload): void
    {
        $token = $payload['token'];

        $subscription = Cashier::$subscriptionModel::where('provider_id', $token)->first();

        if (! $subscription) {
            $customData = json_decode($payload['custom_str1'] ?? '{}', true);

            $subscription = $billable->subscriptions()->create([
                'type' => $customData['subscription_type'] ?? Subscription::DEFAULT_TYPE,
                'provider_id' => $token,
                'status' => Subscription::STATUS_ACTIVE,
                'frequency' => $payload['frequency'] ?? null,
                'cycles' => $payload['cycles'] ?? 0,
            ]);

            $billable->customer?->update(['trial_ends_at' => null]);

            SubscriptionCreated::dispatch($billable, $subscription, $payload);
        } else {
            if ($subscription->status !== Subscription::STATUS_ACTIVE) {
                $subscription->forceFill([
                    'status' => Subscription::STATUS_ACTIVE,
                ])->save();
            }
        }
    }

    protected function findOrCreateBillable(array $payload)
    {
        if (! empty($payload['token'])) {
            $subscription = Cashier::$subscriptionModel::where('provider_id', $payload['token'])->first();
            if ($subscription) {
                return $subscription->billable;
            }
        }

        if (is_callable(Cashier::$resolveBillableUsing)) {
            $resolved = call_user_func(Cashier::$resolveBillableUsing, $payload);

            if ($resolved) {
                return $resolved;
            }
        }

        return $this->findBillableByEmail($payload['email_address'] ?? '');
    }

    protected function findBillableByEmail(string $email)
    {
        if (empty($email)) {
            return null;
        }

        return Cashier::$customerModel::where('email', $email)->first()?->billable;
    }
}
