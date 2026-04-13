<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Support\Facades\Http;

class PayFastClient
{
    protected const VALID_IP_RANGES = [
        ['start' => '197.97.145.144', 'end' => '197.97.145.159'],
        ['start' => '41.74.179.192', 'end' => '41.74.179.223'],
    ];

    public function __construct(
        protected string $merchantId,
        protected string $merchantKey,
        protected string $passphrase,
        protected bool $sandbox = false
    ) {
    }

    /**
     * Build payment data in the exact field order PayFast requires.
     *
     * PayFast field order: merchant details, callback URLs, buyer details,
     * transaction details, custom fields, subscription fields.
     */
    public function buildPaymentData(array $params): array
    {
        // Extract known fields to enforce PayFast's required order
        $ordered = [];

        // 1. Merchant details
        $ordered['merchant_id'] = $this->merchantId;
        $ordered['merchant_key'] = $this->merchantKey;

        // 2. Callback URLs
        foreach (['return_url', 'cancel_url', 'notify_url'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $ordered[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        // 3. Buyer details
        foreach (['name_first', 'name_last', 'email_address', 'cell_number'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $ordered[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        // 4. Transaction details
        foreach (['m_payment_id', 'amount', 'item_name', 'item_description'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $ordered[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        // 5. Custom fields
        foreach (['custom_int1', 'custom_int2', 'custom_int3', 'custom_int4', 'custom_int5',
                   'custom_str1', 'custom_str2', 'custom_str3', 'custom_str4', 'custom_str5'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $ordered[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        // 6. Subscription fields
        foreach (['subscription_type', 'billing_date', 'recurring_amount', 'frequency', 'cycles',
                   'subscription_notify_email', 'subscription_notify_webhook', 'subscription_notify_buyer'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $ordered[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        // 7. Any remaining params not in the known list
        foreach ($params as $key => $val) {
            if ($val !== '') {
                $ordered[$key] = $val;
            }
        }

        $ordered['signature'] = $this->generateSignature($ordered);

        return $ordered;
    }

    /**
     * Generate an MD5 signature from the given data.
     *
     * PayFast requires parameters in their original order (NOT alphabetically sorted).
     * Values are URL-encoded with spaces as '+'.
     */
    public function generateSignature(array $data): string
    {
        unset($data['signature']);

        $pfOutput = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfOutput .= $key.'='.urlencode(trim((string) $val)).'&';
            }
        }

        $pfOutput = substr($pfOutput, 0, -1);

        if ($this->passphrase !== null && $this->passphrase !== '') {
            $pfOutput .= '&passphrase='.urlencode(trim($this->passphrase));
        }

        return md5($pfOutput);
    }

    public function validateSignature(array $data): bool
    {
        $receivedSignature = $data['signature'] ?? '';
        $expectedSignature = $this->generateSignature($data);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    public function isValidIp(string $ip): bool
    {
        $ipLong = ip2long($ip);

        foreach (self::VALID_IP_RANGES as $range) {
            $startLong = ip2long($range['start']);
            $endLong = ip2long($range['end']);

            if ($ipLong >= $startLong && $ipLong <= $endLong) {
                return true;
            }
        }

        return false;
    }

    public function confirmItn(array $data): bool
    {
        $url = $this->baseUrl().'/eng/query/validate';

        $response = Http::asForm()->post($url, $data);

        return $response->body() === 'VALID';
    }

    public function cancelSubscription(string $token): bool
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->put($this->apiUrl()."/subscriptions/{$token}/cancel");

        return $response->successful();
    }

    public function pauseSubscription(string $token): bool
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->put($this->apiUrl()."/subscriptions/{$token}/pause");

        return $response->successful();
    }

    public function unpauseSubscription(string $token): bool
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->put($this->apiUrl()."/subscriptions/{$token}/unpause");

        return $response->successful();
    }

    public function fetchSubscription(string $token): array
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->get($this->apiUrl()."/subscriptions/{$token}/fetch");

        return $response->json() ?? [];
    }

    public function processUrl(): string
    {
        return $this->baseUrl().'/eng/process';
    }

    public function apiUrl(): string
    {
        return 'https://api.payfast.co.za';
    }

    protected function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://sandbox.payfast.co.za'
            : 'https://www.payfast.co.za';
    }

    protected function apiHeaders(): array
    {
        $timestamp = now()->toIso8601String();

        return [
            'merchant-id' => $this->merchantId,
            'version' => 'v1',
            'timestamp' => $timestamp,
            'signature' => $this->generateSignature([
                'merchant-id' => $this->merchantId,
                'passphrase' => $this->passphrase,
                'timestamp' => $timestamp,
                'version' => 'v1',
            ]),
        ];
    }
}
