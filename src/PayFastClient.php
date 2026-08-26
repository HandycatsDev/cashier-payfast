<?php

namespace HandycatsDev\CashierPayFast;

use HandycatsDev\CashierPayFast\Events\ApiRequestFailed;
use HandycatsDev\CashierPayFast\Events\ApiRequestSucceeded;
use HandycatsDev\CashierPayFast\Exceptions\PayFastException;
use Illuminate\Support\Facades\Http;

class PayFastClient
{
    /**
     * The hostnames PayFast sends ITNs from. Resolved at request time rather
     * than pinned as IP ranges, which is what PayFast's own SDK does
     * (lib/PaymentIntegrations/Notification.php::pfValidIP).
     *
     * This class previously carried two hardcoded ranges. PayFast serves ITNs
     * from more addresses than that, and they change — a live notification
     * from an address outside the pinned set is rejected as forged, silently,
     * because the rejection is a 403 at the edge rather than an application
     * error. Resolving the hostnames cannot go stale.
     */
    protected const VALID_ITN_HOSTS = [
        'www.payfast.co.za',
        'sandbox.payfast.co.za',
        'w1w.payfast.co.za',
        'w2w.payfast.co.za',
    ];

    /**
     * Fallback for when DNS is unavailable. Deliberately a floor, not the
     * answer: an ITN from an address outside it is still accepted if the
     * hostnames resolve and list it.
     */
    protected const FALLBACK_IP_RANGES = [
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

    /**
     * Validate the signature on an inbound ITN.
     *
     * NOT {@see generateSignature()}, which signs the OUTBOUND payment form
     * and deliberately omits empty values. PayFast's own SDK
     * (lib/PaymentIntegrations/Notification.php::dataToString) builds the ITN
     * canonical form differently, and the difference is not cosmetic:
     *
     *  - every posted field is included, BLANKS AND ALL. An ITN is mostly
     *    blanks, so signing it by the payment-form rule produces a completely
     *    different MD5 and every genuine notification is rejected as forged;
     *  - iteration STOPS at `signature` rather than skipping it, so fields
     *    posted after it are excluded;
     *  - values are not trimmed.
     *
     * The passphrase is the one genuinely ambiguous part. PayFast's SDK
     * appends it raw (`&passphrase=$passPhrase`) while their documentation
     * examples url-encode it, and their SDK's own test fixture uses an empty
     * passphrase, so it settles nothing. Both forms are accepted rather than
     * guessing: each is derived from the same secret, so tolerating the
     * encoding costs no security, and picking the wrong one rejects every
     * real notification.
     *
     * @param  array<string, mixed>  $data
     */
    public function validateSignature(array $data): bool
    {
        $receivedSignature = (string) ($data['signature'] ?? '');

        if ($receivedSignature === '') {
            return false;
        }

        foreach ($this->itnSignatureCandidates($data) as $candidate) {
            if (hash_equals($candidate, $receivedSignature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every MD5 that could legitimately sign this ITN — one per passphrase
     * encoding. See {@see validateSignature()} for why there is more than one.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function itnSignatureCandidates(array $data): array
    {
        $canonical = '';

        foreach ($data as $key => $val) {
            // Stop AT the signature, do not merely skip it — PayFast signs
            // only what precedes it in the posted order.
            if ($key === 'signature') {
                break;
            }

            $canonical .= $key.'='.urlencode((string) $val).'&';
        }

        $canonical = substr($canonical, 0, -1);

        if ($this->passphrase === null || $this->passphrase === '') {
            return [md5($canonical)];
        }

        return [
            md5($canonical.'&passphrase='.$this->passphrase),
            md5($canonical.'&passphrase='.urlencode($this->passphrase)),
        ];
    }

    /**
     * Generate a signature for the PayFast subscription API, mirroring
     * PayFast's official PHP SDK (lib/Auth.php::generateApiSignature).
     *
     * The canonical form for API requests differs from payment-form
     * signing: the passphrase is added to the array BEFORE sorting and
     * is therefore alphabetised with all other variables, not appended
     * at the end. Mixing the two conventions produces an MD5 that
     * PayFast rejects with HTTP 401 "Merchant authorisation failed".
     *
     * @param  array<string, mixed>  $data
     */
    public function generateApiSignature(array $data): string
    {
        if ($this->passphrase !== null && $this->passphrase !== '') {
            $data['passphrase'] = $this->passphrase;
        }

        unset($data['signature']);

        ksort($data);

        $canonical = '';
        foreach ($data as $key => $val) {
            $canonical .= $key.'='.urlencode((string) $val).'&';
        }

        return md5(rtrim($canonical, '&'));
    }

    /**
     * Whether an ITN genuinely came from PayFast.
     *
     * Resolution is cached for five minutes: this runs on every notification,
     * and a DNS lookup per webhook is both slow and a way to have PayFast's
     * resolver rate-limit you into rejecting real traffic.
     */
    public function isValidIp(string $ip): bool
    {
        if (in_array($ip, $this->resolvedItnIps(), true)) {
            return true;
        }

        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return false;
        }

        foreach (self::FALLBACK_IP_RANGES as $range) {
            if ($ipLong >= ip2long($range['start']) && $ipLong <= ip2long($range['end'])) {
                return true;
            }
        }

        return false;
    }

    /** @var list<string>|null */
    protected static ?array $itnIpCache = null;

    protected static ?int $itnIpCachedAt = null;

    /**
     * Resolved once per process for five minutes.
     *
     * A plain static rather than the Cache facade on purpose: this class is
     * constructed directly in unit tests with no Laravel container, and
     * reaching for a facade here made it un-instantiable outside one. The
     * point of caching is only to avoid a DNS lookup on every notification,
     * which a static achieves without adding a dependency.
     *
     * @return list<string>
     */
    protected function resolvedItnIps(): array
    {
        $now = time();

        if (self::$itnIpCache !== null && self::$itnIpCachedAt !== null && ($now - self::$itnIpCachedAt) < 300) {
            return self::$itnIpCache;
        }

        $ips = [];

        foreach (self::VALID_ITN_HOSTS as $host) {
            $resolved = gethostbynamel($host);

            if (is_array($resolved)) {
                $ips = array_merge($ips, $resolved);
            }
        }

        self::$itnIpCache    = array_values(array_unique($ips));
        self::$itnIpCachedAt = $now;

        return self::$itnIpCache;
    }

    /**
     * Drops the resolved-address cache. For tests, and for an operator who has
     * to force a re-resolve without restarting the process.
     */
    public static function flushItnIpCache(): void
    {
        self::$itnIpCache    = null;
        self::$itnIpCachedAt = null;
    }

    public function confirmItn(array $data): bool
    {
        $url = $this->baseUrl().'/eng/query/validate';

        $response = Http::asForm()->post($url, $data);

        return $response->body() === 'VALID';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayFastException
     */
    public function cancelSubscription(string $token): array
    {
        return $this->request('PUT', $this->apiUrl()."/subscriptions/{$token}/cancel");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayFastException
     */
    public function pauseSubscription(string $token): array
    {
        return $this->request('PUT', $this->apiUrl()."/subscriptions/{$token}/pause");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayFastException
     */
    public function unpauseSubscription(string $token): array
    {
        return $this->request('PUT', $this->apiUrl()."/subscriptions/{$token}/unpause");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayFastException
     */
    public function fetchSubscription(string $token): array
    {
        return $this->request('GET', $this->apiUrl()."/subscriptions/{$token}/fetch");
    }

    /**
     * Update one or more attributes on an existing subscription.
     *
     * PayFast allows mid-cycle updates to `cycles`, `frequency`, `run_date`,
     * and `amount` (in cents, ZAR). Any fields omitted are left unchanged.
     *
     * @param  array{cycles?: int, frequency?: int, run_date?: string, amount?: int}  $attributes
     * @return array<string, mixed>
     *
     * @throws PayFastException
     */
    public function updateSubscription(string $token, array $attributes): array
    {
        return $this->request(
            method: 'PATCH',
            url: $this->apiUrl()."/subscriptions/{$token}/update",
            body: $attributes,
        );
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

    /**
     * Execute an authenticated PayFast API request.
     *
     * Dispatches ApiRequestSucceeded on success, ApiRequestFailed on failure,
     * and throws PayFastException carrying the full PayFast error envelope.
     *
     * @return array<string, mixed>
     *
     * @throws PayFastException
     */
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PayFastException
     */
    private function request(string $method, string $url, array $body = []): array
    {
        // PayFast's subscription API uses a single host for live and sandbox,
        // but sandbox calls must carry ?testing=true or they return HTTP 401
        // "Merchant authorisation failed" because the sandbox merchant
        // credentials do not match a live account.
        if ($this->sandbox) {
            $url .= (str_contains($url, '?') ? '&' : '?').'testing=true';
        }

        $http = Http::withHeaders($this->apiHeaders($body));

        // PayFast expects form-encoded bodies for POST/PATCH operations on
        // the subscription API (matching the payment form semantics).
        $response = $body !== []
            ? $http->asForm()->{strtolower($method)}($url, $body)
            : $http->{strtolower($method)}($url);

        $body = $response->json() ?? [];
        $statusIsFailed = isset($body['status']) && $body['status'] === 'failed';

        if ($response->failed() || $statusIsFailed) {
            $errorCode    = isset($body['code']) && is_scalar($body['code']) ? (string) $body['code'] : null;
            $errorStatus  = $body['status'] ?? null;
            $errorMessage = $this->extractErrorMessage($body);

            ApiRequestFailed::dispatch(
                method: $method,
                url: $url,
                statusCode: $response->status(),
                errorCode: $errorCode,
                errorStatus: $errorStatus,
                errorMessage: $errorMessage,
                responseBody: $body,
            );

            // PayFast sometimes returns a non-JSON body (e.g. on 401) or a
            // JSON body without a usable message field. Fall back to a
            // truncated raw body so the exception message is never empty.
            $messageForException = $errorMessage ?? $this->summariseResponseBody($response->body());

            $exceptionMessage = sprintf(
                "PayFast API error '%s' occurred on %s %s (HTTP %d)",
                $messageForException,
                $method,
                $url,
                $response->status(),
            );

            throw (new PayFastException($exceptionMessage))->setError($body);
        }

        ApiRequestSucceeded::dispatch(
            method: $method,
            url: $url,
            statusCode: $response->status(),
            responseBody: $body,
        );

        return $body;
    }

    /**
     * Extract a usable error message from PayFast's error envelope.
     *
     * PayFast's API is inconsistent: for most endpoints the text lives at
     * `data.message`, but on some errors (e.g. PATCH /subscriptions/.../update
     * returning 401) the text is actually at `data.response` and `data.message`
     * is literally `false`. Prefer whichever field is a non-empty string.
     *
     * @param  array<string, mixed>  $body
     */
    private function extractErrorMessage(array $body): ?string
    {
        $message = $body['data']['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        $response = $body['data']['response'] ?? null;
        if (is_string($response) && $response !== '') {
            return $response;
        }

        return null;
    }

    /**
     * Produce a safe single-line summary of a response body for use in an
     * exception message. Returns a generic label if the body is empty.
     */
    private function summariseResponseBody(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return 'empty response body';
        }

        $oneLine = preg_replace('/\s+/', ' ', $trimmed);
        $max     = 200;

        return mb_strlen($oneLine) > $max
            ? mb_substr($oneLine, 0, $max).'…'
            : $oneLine;
    }

    /**
     * Build request headers for the subscription API.
     *
     * PayFast requires the signature to be computed over the alphabetised
     * union of header variables (merchant-id, version, timestamp) and any
     * body variables, plus the passphrase. Endpoints with no body pass an
     * empty array.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    protected function apiHeaders(array $body = []): array
    {
        $timestamp = now()->toIso8601String();

        // PayFast's subscription API requires the canonical form produced
        // by generateApiSignature() — passphrase alphabetised inside the
        // sorted array, not appended at the end. See generateApiSignature()
        // for the rationale.
        $signature = $this->generateApiSignature([
            'merchant-id' => $this->merchantId,
            'timestamp'   => $timestamp,
            'version'     => 'v1',
            ...$body,
        ]);

        return [
            'merchant-id' => $this->merchantId,
            'version'     => 'v1',
            'timestamp'   => $timestamp,
            'signature'   => $signature,
        ];
    }
}
