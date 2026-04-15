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
            // Expected — verify DB state was not mutated
            $subscription->refresh();
            $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->status);
            $this->assertNull($subscription->ends_at);
            return;
        }

        $this->fail('Expected PayFastException was not thrown');
    }
}
