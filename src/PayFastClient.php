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

    public function buildPaymentData(array $params): array
    {
        $data = array_merge([
            'merchant_id' => $this->merchantId,
            'merchant_key' => $this->merchantKey,
        ], $params);

        $data['signature'] = $this->generateSignature($data);

        return $data;
    }

    public function generateSignature(array $data): string
    {
        unset($data['signature']);

        ksort($data);

        $pfOutput = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfOutput .= $key.'='.urlencode(trim($val)).'&';
            }
        }

        $pfOutput = rtrim($pfOutput, '&');

        if ($this->passphrase !== '') {
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
