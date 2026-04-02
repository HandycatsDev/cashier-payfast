<?php

namespace Tests\Unit;

use HandycatsDev\CashierPayFast\PayFastClient;
use PHPUnit\Framework\TestCase;

class PayFastClientTest extends TestCase
{
    public function test_generates_signature()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $data = [
            'merchant_id' => '10000100',
            'merchant_key' => '46f0cd694581a',
            'amount' => '100.00',
            'item_name' => 'Test Product',
        ];

        $signature = $client->generateSignature($data);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $signature);
    }

    public function test_builds_payment_data_with_signature()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $data = $client->buildPaymentData([
            'amount' => '100.00',
            'item_name' => 'Test Product',
        ]);

        $this->assertEquals('10000100', $data['merchant_id']);
        $this->assertEquals('46f0cd694581a', $data['merchant_key']);
        $this->assertEquals('100.00', $data['amount']);
        $this->assertEquals('Test Product', $data['item_name']);
        $this->assertArrayHasKey('signature', $data);
    }

    public function test_returns_sandbox_process_url()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $this->assertEquals('https://sandbox.payfast.co.za/eng/process', $client->processUrl());
    }

    public function test_returns_production_process_url()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: false
        );

        $this->assertEquals('https://www.payfast.co.za/eng/process', $client->processUrl());
    }

    public function test_returns_api_url()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $this->assertEquals('https://api.payfast.co.za', $client->apiUrl());
    }

    public function test_validate_signature_returns_true_for_valid_signature()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $data = [
            'merchant_id' => '10000100',
            'merchant_key' => '46f0cd694581a',
            'amount' => '100.00',
            'item_name' => 'Test Product',
        ];

        $signature = $client->generateSignature($data);
        $data['signature'] = $signature;

        $this->assertTrue($client->validateSignature($data));
    }

    public function test_validate_signature_returns_false_for_invalid_signature()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $data = [
            'merchant_id' => '10000100',
            'merchant_key' => '46f0cd694581a',
            'amount' => '100.00',
            'item_name' => 'Test Product',
            'signature' => 'invalid_signature_here',
        ];

        $this->assertFalse($client->validateSignature($data));
    }

    public function test_known_ip_ranges()
    {
        $client = new PayFastClient(
            merchantId: '10000100',
            merchantKey: '46f0cd694581a',
            passphrase: 'jt7NOE43FZPn',
            sandbox: true
        );

        $this->assertTrue($client->isValidIp('197.97.145.144'));
        $this->assertTrue($client->isValidIp('197.97.145.155'));
        $this->assertTrue($client->isValidIp('41.74.179.192'));
        $this->assertTrue($client->isValidIp('41.74.179.210'));

        $this->assertFalse($client->isValidIp('192.168.1.1'));
        $this->assertFalse($client->isValidIp('10.0.0.1'));
    }
}
