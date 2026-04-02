<?php

namespace Tests\Unit;

use HandycatsDev\CashierPayFast\Checkout;
use HandycatsDev\CashierPayFast\PayFastClient;
use PHPUnit\Framework\TestCase;

class CheckoutTest extends TestCase
{
    public function test_builds_fields_with_merchant_data()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $checkout = new Checkout($client, [
            'amount' => '100.00',
            'item_name' => 'Test Product',
        ]);

        $fields = $checkout->fields();

        $this->assertEquals('10000100', $fields['merchant_id']);
        $this->assertEquals('46f0cd694581a', $fields['merchant_key']);
        $this->assertEquals('100.00', $fields['amount']);
        $this->assertEquals('Test Product', $fields['item_name']);
        $this->assertArrayHasKey('signature', $fields);
    }

    public function test_includes_custom_data_as_custom_str1()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $checkout = new Checkout($client, [
            'amount' => '100.00',
            'item_name' => 'Test Product',
        ]);

        $checkout->customData(['subscription_type' => 'premium']);

        $fields = $checkout->fields();

        $this->assertArrayHasKey('custom_str1', $fields);
        $decoded = json_decode($fields['custom_str1'], true);
        $this->assertEquals('premium', $decoded['subscription_type']);
    }

    public function test_returns_sandbox_url()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $checkout = new Checkout($client, []);

        $this->assertEquals('https://sandbox.payfast.co.za/eng/process', $checkout->url());
    }
}
