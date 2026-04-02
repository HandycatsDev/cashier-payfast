<?php

namespace Tests\Feature;

use HandycatsDev\CashierPayFast\Cashier;
use HandycatsDev\CashierPayFast\Events\PaymentComplete;
use HandycatsDev\CashierPayFast\Events\PaymentFailed;
use HandycatsDev\CashierPayFast\Events\SubscriptionCanceled;
use HandycatsDev\CashierPayFast\Events\SubscriptionCreated;
use HandycatsDev\CashierPayFast\PayFastClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Workbench\App\Models\User;

class WebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PayFastClient::class, function ($mock) {
            $mock->shouldReceive('isValidIp')->andReturn(true);
            $mock->shouldReceive('validateSignature')->andReturn(true);
            $mock->shouldReceive('confirmItn')->andReturn(true);
        });
    }

    public function test_complete_payment_creates_transaction()
    {
        Event::fake();

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->createAsCustomer([
            'provider_id' => 'test-token',
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $this->post(route('cashier.webhook'), [
            'payment_status' => 'COMPLETE',
            'pf_payment_id' => '1234567',
            'email_address' => 'test@example.com',
            'amount_gross' => '100.00',
            'amount_fee' => '-2.30',
            'amount_net' => '97.70',
            'token' => 'sub-token-123',
            'custom_str1' => json_encode(['subscription_type' => 'default']),
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'provider_id' => '1234567',
            'payment_status' => 'COMPLETE',
        ]);

        Event::assertDispatched(PaymentComplete::class);
        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_failed_payment_fires_event()
    {
        Event::fake();

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->createAsCustomer([
            'provider_id' => 'test-token',
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $this->post(route('cashier.webhook'), [
            'payment_status' => 'FAILED',
            'pf_payment_id' => '1234568',
            'email_address' => 'test@example.com',
            'amount_gross' => '100.00',
            'amount_fee' => '0.00',
            'amount_net' => '100.00',
        ])->assertOk();

        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_cancelled_subscription_updates_status()
    {
        Event::fake();

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $user->createAsCustomer([
            'provider_id' => 'test-token',
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $user->subscriptions()->create([
            'type' => 'default',
            'provider_id' => 'sub-token-456',
            'status' => 'active',
        ]);

        $this->post(route('cashier.webhook'), [
            'payment_status' => 'CANCELLED',
            'token' => 'sub-token-456',
            'email_address' => 'test@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'provider_id' => 'sub-token-456',
            'status' => 'canceled',
        ]);

        Event::assertDispatched(SubscriptionCanceled::class);
    }
}
