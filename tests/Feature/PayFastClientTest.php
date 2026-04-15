<?php

namespace Tests\Feature;

use HandycatsDev\CashierPayFast\Events\ApiRequestFailed;
use HandycatsDev\CashierPayFast\Events\ApiRequestSucceeded;
use HandycatsDev\CashierPayFast\Exceptions\PayFastException;
use HandycatsDev\CashierPayFast\PayFastClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayFastClientTest extends TestCase
{
    private function makeClient(): PayFastClient
    {
        return new PayFastClient('10000100', 'test-key', 'test-passphrase', true);
    }

    public function test_cancel_subscription_returns_body_and_dispatches_succeeded_event_on_200(): void
    {
        Event::fake();
        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel' => Http::response([
                'code'   => 200,
                'status' => 'success',
                'data'   => ['response' => true],
            ], 200),
        ]);

        $result = $this->makeClient()->cancelSubscription('tok-123');

        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals(200, $result['code']);
        $this->assertEquals('success', $result['status']);

        Event::assertDispatched(ApiRequestSucceeded::class, function ($event) {
            return $event->method === 'PUT'
                && str_contains($event->url, '/subscriptions/tok-123/cancel')
                && $event->statusCode === 200;
        });
    }

    public function test_cancel_subscription_throws_payfast_exception_with_attached_error_envelope_on_400(): void
    {
        Event::fake();
        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel' => Http::response([
                'code'   => 400,
                'status' => 'failed',
                'data'   => [
                    'response' => false,
                    'message'  => 'Merchant authorisation failed',
                ],
            ], 400),
        ]);

        try {
            $this->makeClient()->cancelSubscription('tok-123');
            $this->fail('Expected PayFastException was not thrown');
        } catch (PayFastException $e) {
            $this->assertStringContainsString('Merchant authorisation failed', $e->getMessage());
            $this->assertEquals(400, $e->getError()['code']);
            $this->assertEquals('failed', $e->getError()['status']);
            $this->assertEquals('Merchant authorisation failed', $e->getError()['data']['message']);
        }

        Event::assertDispatched(ApiRequestFailed::class, function ($event) {
            return $event->statusCode === 400
                && $event->errorStatus === 'failed'
                && $event->errorMessage === 'Merchant authorisation failed';
        });
    }

    public function test_cancel_subscription_throws_payfast_exception_on_500_application_error(): void
    {
        Event::fake();
        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel' => Http::response([
                'code'   => 500,
                'status' => 'failed',
                'data'   => ['message' => 'Application Error'],
            ], 500),
        ]);

        try {
            $this->makeClient()->cancelSubscription('tok-123');
            $this->fail('Expected PayFastException was not thrown');
        } catch (PayFastException $e) {
            $this->assertEquals(500, $e->getError()['code']);
        }

        Event::assertDispatched(ApiRequestFailed::class, fn ($e) => $e->statusCode === 500);
    }
}
