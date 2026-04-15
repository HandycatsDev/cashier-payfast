<?php

namespace Tests\Feature;

use HandycatsDev\CashierPayFast\Exceptions\PayFastException;
use HandycatsDev\CashierPayFast\Subscription;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workbench\App\Models\User;

class SubscriptionModelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_subscription_cancel_leaves_local_status_untouched_when_payfast_throws(): void
    {
        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel' => Http::response([
                'code'   => 400,
                'status' => 'failed',
                'data'   => ['response' => false, 'message' => 'Merchant authorisation failed'],
            ], 400),
        ]);

        $user = User::create([
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->createAsCustomer([
            'provider_id' => 'cust-token',
            'email'       => 'test@example.com',
            'name'        => 'Test User',
        ]);

        /** @var Subscription $subscription */
        $subscription = $user->subscriptions()->create([
            'type'        => 'default',
            'provider_id' => 'tok-123',
            'status'      => Subscription::STATUS_ACTIVE,
            'ends_at'     => null,
        ]);

        $this->expectException(PayFastException::class);

        $subscription->cancel();

        // These assertions run only if cancel() does not throw (i.e., they are the failure case)
        $subscription->refresh();
        $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->ends_at);
    }

    public function test_subscription_cancel_leaves_database_untouched_when_payfast_throws(): void
    {
        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel' => Http::response([
                'code'   => 400,
                'status' => 'failed',
                'data'   => ['response' => false, 'message' => 'Merchant authorisation failed'],
            ], 400),
        ]);

        $user = User::create([
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->createAsCustomer([
            'provider_id' => 'cust-token',
            'email'       => 'test@example.com',
            'name'        => 'Test User',
        ]);

        /** @var Subscription $subscription */
        $subscription = $user->subscriptions()->create([
            'type'        => 'default',
            'provider_id' => 'tok-123',
            'status'      => Subscription::STATUS_ACTIVE,
            'ends_at'     => null,
        ]);

        try {
            $subscription->cancel();
        } catch (PayFastException $e) {
            // Expected — verify DB state was rolled back to original values
            $subscription->refresh();
            $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->status);
            $this->assertNull($subscription->ends_at);
            return;
        }

        $this->fail('Expected PayFastException was not thrown');
    }

    public function test_subscription_cancel_writes_ends_at_to_database_before_api_call(): void
    {
        // This test guards against a race where PayFast's CANCELLED ITN can
        // arrive at our webhook BEFORE the cancel() HTTP call returns to
        // PHP. If we set ends_at after the API call, the webhook handler
        // sees ends_at IS NULL, sets it to now(), and silently throws away
        // the grace period. By writing optimistically before the API call,
        // any concurrent reader (or webhook) sees the correct future date.
        $user = User::create([
            'name'     => 'Race Test',
            'email'    => 'race@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->createAsCustomer([
            'provider_id' => 'cust-race',
            'email'       => 'race@example.com',
            'name'        => 'Race Test',
        ]);

        /** @var Subscription $subscription */
        $subscription = $user->subscriptions()->create([
            'type'        => 'default',
            'provider_id' => 'tok-race',
            'status'      => Subscription::STATUS_ACTIVE,
            'ends_at'     => null,
        ]);

        $graceEndsAt = now()->addDays(20);

        // While the fake HTTP call is "executing", read the DB and assert
        // ends_at is already populated. This proves the optimistic write
        // happened BEFORE the API call, not after it.
        $observedEndsAtMidCall = null;

        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel*' => function () use ($subscription, &$observedEndsAtMidCall) {
                $observedEndsAtMidCall = $subscription->newQuery()
                    ->whereKey($subscription->getKey())
                    ->value('ends_at');

                return Http::response([
                    'code'   => 200,
                    'status' => 'success',
                    'data'   => ['response' => true],
                ], 200);
            },
        ]);

        $subscription->cancel($graceEndsAt);

        $this->assertNotNull($observedEndsAtMidCall, 'ends_at must be set in DB before the API call');
        $this->assertSame(
            $graceEndsAt->toDateTimeString(),
            \Carbon\Carbon::parse($observedEndsAtMidCall)->toDateTimeString(),
            'ends_at observed mid-call must equal the grace-period date passed to cancel()',
        );
    }
}
