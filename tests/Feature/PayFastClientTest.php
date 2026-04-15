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
            'api.payfast.co.za/subscriptions/*/cancel*' => Http::response([
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
            'api.payfast.co.za/subscriptions/*/cancel*' => Http::response([
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
            'api.payfast.co.za/subscriptions/*/cancel*' => Http::response([
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

    public function test_cancel_subscription_appends_testing_query_param_in_sandbox_mode(): void
    {
        Http::fake([
            '*' => Http::response(['code' => 200, 'status' => 'success', 'data' => ['response' => true]], 200),
        ]);

        $sandboxClient = new PayFastClient('10000100', 'test-key', 'test-passphrase', sandbox: true);

        $sandboxClient->cancelSubscription('tok-abc');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/subscriptions/tok-abc/cancel')
                && str_contains($request->url(), 'testing=true');
        });
    }

    public function test_cancel_subscription_does_not_append_testing_query_param_in_live_mode(): void
    {
        Http::fake([
            '*' => Http::response(['code' => 200, 'status' => 'success', 'data' => ['response' => true]], 200),
        ]);

        $liveClient = new PayFastClient('10000100', 'test-key', 'test-passphrase', sandbox: false);

        $liveClient->cancelSubscription('tok-abc');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/subscriptions/tok-abc/cancel')
                && ! str_contains($request->url(), 'testing=true');
        });
    }

    public function test_exception_message_falls_back_to_raw_body_when_message_is_empty(): void
    {
        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel*' => Http::response('Unauthorized', 401),
        ]);

        try {
            $this->makeClient()->cancelSubscription('tok-xyz');
            $this->fail('Expected PayFastException was not thrown');
        } catch (PayFastException $e) {
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString("''", $e->getMessage());
        }
    }

    public function test_exception_message_reports_empty_body_when_response_has_no_body(): void
    {
        Http::fake([
            'api.payfast.co.za/subscriptions/*/cancel*' => Http::response('', 401),
        ]);

        try {
            $this->makeClient()->cancelSubscription('tok-xyz');
            $this->fail('Expected PayFastException was not thrown');
        } catch (PayFastException $e) {
            $this->assertStringContainsString('empty response body', $e->getMessage());
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }
    }

    public function test_update_subscription_patches_with_form_body_and_returns_response(): void
    {
        Http::fake([
            'api.payfast.co.za/subscriptions/*/update*' => Http::response([
                'code'   => 200,
                'status' => 'success',
                'data'   => ['response' => true],
            ], 200),
        ]);

        $result = $this->makeClient()->updateSubscription('tok-abc', ['amount' => 1500]);

        $this->assertEquals('success', $result['status']);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/subscriptions/tok-abc/update')
                && $request['amount'] === 1500;
        });
    }

    public function test_update_subscription_throws_on_payfast_error(): void
    {
        Http::fake([
            'api.payfast.co.za/subscriptions/*/update*' => Http::response([
                'code'   => 400,
                'status' => 'failed',
                'data'   => ['response' => false, 'message' => 'Invalid amount'],
            ], 400),
        ]);

        try {
            $this->makeClient()->updateSubscription('tok-abc', ['amount' => -1]);
            $this->fail('Expected PayFastException was not thrown');
        } catch (PayFastException $e) {
            $this->assertStringContainsString('Invalid amount', $e->getMessage());
            $this->assertEquals(400, $e->getError()['code']);
        }
    }

    public function test_update_subscription_appends_testing_query_param_in_sandbox_mode(): void
    {
        Http::fake([
            '*' => Http::response(['code' => 200, 'status' => 'success', 'data' => ['response' => true]], 200),
        ]);

        $sandbox = new PayFastClient('10000100', 'test-key', 'test-passphrase', sandbox: true);
        $sandbox->updateSubscription('tok-abc', ['amount' => 1500]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'testing=true'));
    }

    public function test_generate_api_signature_matches_payfasts_canonical_form(): void
    {
        // PayFast's subscription API canonical form (matches their official
        // PHP SDK at lib/Auth.php::generateApiSignature):
        //
        //   1. Insert passphrase into the variable array
        //   2. Alphabetically sort by key (so passphrase is sorted in line)
        //   3. Build "key=urlencoded(value)" pairs joined by &
        //   4. md5 the resulting string
        //
        // For a PATCH /update with amount=45000, the canonical string is:
        //   amount=45000&merchant-id=10000100&passphrase=jt7NOE43FZPn&timestamp=...&version=v1
        //
        // Note passphrase is *between* merchant-id and timestamp alphabetically,
        // NOT appended at the end. That distinction was the reason the API
        // returned HTTP 401 before this test existed.
        $client = new PayFastClient('10000100', 'test-key', 'jt7NOE43FZPn', sandbox: false);

        $timestamp = '2026-04-15T12:00:00+00:00';

        $sorted = [
            'amount'      => 45000,
            'merchant-id' => '10000100',
            'passphrase'  => 'jt7NOE43FZPn',
            'timestamp'   => $timestamp,
            'version'     => 'v1',
        ];

        $canonical = '';
        foreach ($sorted as $key => $val) {
            $canonical .= $key.'='.urlencode((string) $val).'&';
        }
        $canonical = rtrim($canonical, '&');

        $expected = md5($canonical);

        $actual = $client->generateApiSignature([
            'amount'      => 45000,
            'merchant-id' => '10000100',
            'timestamp'   => $timestamp,
            'version'     => 'v1',
        ]);

        $this->assertSame($expected, $actual);
    }

    public function test_generate_api_signature_alphabetises_passphrase_with_other_keys(): void
    {
        // Defensive: assert that passphrase ends up between alphabetically-
        // adjacent keys, NOT at the end. If a future refactor reverts to
        // payment-form-style signing (passphrase appended at the end), this
        // test fails immediately.
        $client = new PayFastClient('10000100', 'test-key', 'jt7NOE43FZPn', sandbox: false);

        $expectedAtEnd = md5(
            'merchant-id=10000100&timestamp=2026-04-15T12:00:00%2B00:00&version=v1&passphrase=jt7NOE43FZPn'
        );

        $expectedAlphabetised = md5(
            'merchant-id=10000100&passphrase=jt7NOE43FZPn&timestamp=2026-04-15T12%3A00%3A00%2B00%3A00&version=v1'
        );

        $actual = $client->generateApiSignature([
            'merchant-id' => '10000100',
            'timestamp'   => '2026-04-15T12:00:00+00:00',
            'version'     => 'v1',
        ]);

        $this->assertNotSame($expectedAtEnd, $actual, 'Passphrase must NOT be appended at end of canonical form');
        $this->assertSame($expectedAlphabetised, $actual);
    }

    public function test_exception_message_falls_back_to_data_response_when_message_is_false(): void
    {
        Http::fake([
            'api.payfast.co.za/subscriptions/*/update*' => Http::response([
                'code'   => 401,
                'status' => 'failed',
                'data'   => [
                    'response' => 'Merchant authorization failed.',
                    'message'  => false,
                ],
            ], 401),
        ]);

        try {
            $this->makeClient()->updateSubscription('tok-xyz', ['amount' => 45000]);
            $this->fail('Expected PayFastException was not thrown');
        } catch (PayFastException $e) {
            $this->assertStringContainsString('Merchant authorization failed.', $e->getMessage());
            $this->assertStringNotContainsString("''", $e->getMessage());
        }
    }
}
