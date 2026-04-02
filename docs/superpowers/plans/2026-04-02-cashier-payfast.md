# Cashier PayFast Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the forked laravel/cashier-paddle package into a fully functional PayFast integration under the `HandycatsDev\CashierPayFast` namespace.

**Architecture:** Adapter pattern — a `PayFastClient` class encapsulates all PayFast HTTP communication (redirect checkout, ITN validation, subscription API). The Cashier interface (Billable trait, models, SubscriptionBuilder) stays familiar but internals call PayFast. Redirect-based checkout replaces Paddle's JS overlay.

**Tech Stack:** PHP 8.1+, Laravel 10/11/12/13, Guzzle HTTP, moneyphp/money, PayFast REST API + ITN webhooks.

**Spec:** `docs/superpowers/specs/2026-04-02-cashier-payfast-design.md`

---

## Task 1: Namespace Rename — Composer & Autoload

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Update composer.json namespace and provider**

Replace the `Laravel\Paddle` autoload and service provider references:

```json
// In "autoload" -> "psr-4":
// Change: "Laravel\\Paddle\\": "src/"
// To:
"HandycatsDev\\CashierPayFast\\": "src/"

// In "extra" -> "laravel" -> "providers":
// Change: "Laravel\\Paddle\\CashierServiceProvider"
// To:
"HandycatsDev\\CashierPayFast\\CashierServiceProvider"
```

Also remove the `paddle-billing` dependency references if any exist. The `spatie/url` and `moneyphp/money` dependencies stay.

- [ ] **Step 2: Run composer dump-autoload to verify**

Run: `composer dump-autoload --no-interaction 2>&1 | head -20`
Expected: Will show errors about missing classes (expected — we haven't renamed PHP files yet). The autoload map itself should generate.

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "chore: update composer.json namespace to HandycatsDev\CashierPayFast"
```

---

## Task 2: Rename Exception Class

**Files:**
- Modify: `src/Exceptions/PaddleException.php`

- [ ] **Step 1: Rename the exception class and namespace**

Replace the full file contents of `src/Exceptions/PaddleException.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Exceptions;

use Exception;

class PayFastException extends Exception
{
    /**
     * The error response from PayFast.
     */
    protected array $error = [];

    /**
     * Get the error response from PayFast.
     */
    public function getError(): array
    {
        return $this->error;
    }

    /**
     * Set the error response from PayFast.
     */
    public function setError(array $error): self
    {
        $this->error = $error;

        return $this;
    }
}
```

- [ ] **Step 2: Rename the file**

```bash
mv src/Exceptions/PaddleException.php src/Exceptions/PayFastException.php
```

- [ ] **Step 3: Commit**

```bash
git add src/Exceptions/
git commit -m "chore: rename PaddleException to PayFastException"
```

---

## Task 3: Rename Event Classes

**Files:**
- Modify: all files in `src/Events/`

- [ ] **Step 1: Update namespace in all event files**

For each file in `src/Events/`, change:
- `namespace Laravel\Paddle\Events;` → `namespace HandycatsDev\CashierPayFast\Events;`
- `use Laravel\Paddle\Subscription;` → `use HandycatsDev\CashierPayFast\Subscription;`
- `use Laravel\Paddle\Customer;` → `use HandycatsDev\CashierPayFast\Customer;`
- `use Laravel\Paddle\Transaction;` → `use HandycatsDev\CashierPayFast\Transaction;`

Files to update (all follow the same pattern):
- `src/Events/SubscriptionCreated.php`
- `src/Events/SubscriptionUpdated.php`
- `src/Events/SubscriptionCanceled.php`
- `src/Events/SubscriptionPaused.php`
- `src/Events/TransactionCompleted.php`
- `src/Events/TransactionUpdated.php`
- `src/Events/CustomerUpdated.php`
- `src/Events/WebhookReceived.php`
- `src/Events/WebhookHandled.php`

Also add two new event files:

**Create `src/Events/PaymentComplete.php`:**
```php
<?php

namespace HandycatsDev\CashierPayFast\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use HandycatsDev\CashierPayFast\Transaction;

class PaymentComplete
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Model $billable,
        public Transaction $transaction,
        public array $payload
    ) {
    }
}
```

**Create `src/Events/PaymentFailed.php`:**
```php
<?php

namespace HandycatsDev\CashierPayFast\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?Model $billable,
        public array $payload
    ) {
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Events/
git commit -m "chore: rename event namespaces to HandycatsDev\CashierPayFast"
```

---

## Task 4: Config File Rewrite

**Files:**
- Modify: `config/cashier.php`

- [ ] **Step 1: Replace config file contents**

Replace `config/cashier.php` with:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PayFast Credentials
    |--------------------------------------------------------------------------
    |
    | Your PayFast merchant credentials. The merchant ID and key are used
    | to authenticate with PayFast. The passphrase is used for signature
    | generation and ITN validation.
    |
    */

    'merchant_id' => env('CASHIER_MERCHANT_ID'),

    'merchant_key' => env('CASHIER_MERCHANT_KEY'),

    'passphrase' => env('CASHIER_PASSPHRASE'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's views, such as the webhook
    | route, will be available. You're free to tweak this path based on
    | the needs of your particular application or design preferences.
    |
    */

    'path' => env('CASHIER_PATH', 'cashier'),

    /*
    |--------------------------------------------------------------------------
    | Cashier URLs
    |--------------------------------------------------------------------------
    |
    | These URLs control where users are redirected after payment and where
    | PayFast sends ITN (Instant Transaction Notification) callbacks.
    |
    */

    'return_url' => env('CASHIER_RETURN_URL', '/cashier/return'),

    'cancel_url' => env('CASHIER_CANCEL_URL', '/cashier/cancel'),

    'notify_url' => env('CASHIER_NOTIFY_URL', '/cashier/webhook'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. PayFast primarily supports ZAR.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'ZAR'),

    /*
    |--------------------------------------------------------------------------
    | Currency Locale
    |--------------------------------------------------------------------------
    |
    | This is the default locale in which your money values are formatted in
    | for display. To utilize other locales besides the default en locale
    | verify you have the "intl" PHP extension installed on the system.
    |
    */

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en_ZA'),

    /*
    |--------------------------------------------------------------------------
    | PayFast Sandbox
    |--------------------------------------------------------------------------
    |
    | This option allows you to toggle between the PayFast live environment
    | and its sandbox environment for testing.
    |
    */

    'sandbox' => env('CASHIER_SANDBOX', false),

];
```

- [ ] **Step 2: Commit**

```bash
git add config/cashier.php
git commit -m "chore: replace Paddle config with PayFast credentials"
```

---

## Task 5: Database Migrations Rewrite

**Files:**
- Modify: `database/migrations/2019_05_03_000001_create_customers_table.php`
- Modify: `database/migrations/2019_05_03_000002_create_subscriptions_table.php`
- Modify: `database/migrations/2019_05_03_000003_create_subscription_items_table.php`
- Modify: `database/migrations/2019_05_03_000004_create_transactions_table.php`

- [ ] **Step 1: Rewrite customers migration**

Replace `database/migrations/2019_05_03_000001_create_customers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('provider_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
```

- [ ] **Step 2: Rewrite subscriptions migration**

Replace `database/migrations/2019_05_03_000002_create_subscriptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('type');
            $table->string('provider_id')->unique();
            $table->string('status');
            $table->unsignedTinyInteger('frequency')->nullable();
            $table->unsignedInteger('cycles')->default(0);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
```

- [ ] **Step 3: Subscription items migration stays the same structurally**

Replace `database/migrations/2019_05_03_000003_create_subscription_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('product_id');
            $table->string('price_id');
            $table->string('status');
            $table->integer('quantity');
            $table->timestamps();

            $table->unique(['subscription_id', 'price_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
```

- [ ] **Step 4: Rewrite transactions migration**

Replace `database/migrations/2019_05_03_000004_create_transactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('provider_id')->unique();
            $table->string('provider_subscription_id')->nullable()->index();
            $table->string('payment_status');
            $table->decimal('amount_gross', 10, 2);
            $table->decimal('amount_fee', 10, 2)->default(0);
            $table->decimal('amount_net', 10, 2);
            $table->string('currency', 3)->default('ZAR');
            $table->timestamp('billed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/
git commit -m "chore: rewrite migrations for PayFast schema (provider_id, amounts)"
```

---

## Task 6: PayFastClient — Test First

**Files:**
- Create: `tests/Unit/PayFastClientTest.php`
- Create: `src/PayFastClient.php`

- [ ] **Step 1: Write the failing tests for PayFastClient**

Create `tests/Unit/PayFastClientTest.php`:

```php
<?php

namespace Tests\Unit;

use HandycatsDev\CashierPayFast\PayFastClient;
use PHPUnit\Framework\TestCase;

class PayFastClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

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

        // Signature should be a 32-char MD5 hex string
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

        // PayFast known IPs
        $this->assertTrue($client->isValidIp('197.97.145.144'));
        $this->assertTrue($client->isValidIp('197.97.145.155'));
        $this->assertTrue($client->isValidIp('41.74.179.192'));
        $this->assertTrue($client->isValidIp('41.74.179.210'));

        // Invalid IPs
        $this->assertFalse($client->isValidIp('192.168.1.1'));
        $this->assertFalse($client->isValidIp('10.0.0.1'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/PayFastClientTest.php 2>&1 | tail -5`
Expected: FAIL — class not found

- [ ] **Step 3: Implement PayFastClient**

Create `src/PayFastClient.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Support\Facades\Http;

class PayFastClient
{
    /**
     * PayFast's known valid IP ranges for ITN.
     */
    protected const VALID_IP_RANGES = [
        ['start' => '197.97.145.144', 'end' => '197.97.145.159'],   // /28
        ['start' => '41.74.179.192', 'end' => '41.74.179.223'],     // /27
    ];

    public function __construct(
        protected string $merchantId,
        protected string $merchantKey,
        protected string $passphrase,
        protected bool $sandbox = false
    ) {
    }

    /**
     * Build payment form data with merchant credentials and signature.
     */
    public function buildPaymentData(array $params): array
    {
        $data = array_merge([
            'merchant_id' => $this->merchantId,
            'merchant_key' => $this->merchantKey,
        ], $params);

        $data['signature'] = $this->generateSignature($data);

        return $data;
    }

    /**
     * Generate an MD5 signature from the given data.
     *
     * PayFast signature: sort params alphabetically, URL-encode values,
     * join with &, append passphrase, MD5 hash the result.
     */
    public function generateSignature(array $data): string
    {
        // Remove signature if present
        unset($data['signature']);

        // Sort alphabetically by key
        ksort($data);

        // Build query string
        $pfOutput = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfOutput .= $key.'='.urlencode(trim($val)).'&';
            }
        }

        // Remove trailing &
        $pfOutput = rtrim($pfOutput, '&');

        // Append passphrase
        if ($this->passphrase !== '') {
            $pfOutput .= '&passphrase='.urlencode(trim($this->passphrase));
        }

        return md5($pfOutput);
    }

    /**
     * Validate the signature of incoming ITN data.
     */
    public function validateSignature(array $data): bool
    {
        $receivedSignature = $data['signature'] ?? '';
        $expectedSignature = $this->generateSignature($data);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    /**
     * Validate that an IP address belongs to PayFast.
     */
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

    /**
     * Confirm ITN data with PayFast's server.
     */
    public function confirmItn(array $data): bool
    {
        $url = $this->baseUrl().'/eng/query/validate';

        $response = Http::asForm()->post($url, $data);

        return $response->body() === 'VALID';
    }

    /**
     * Cancel a subscription via PayFast API.
     */
    public function cancelSubscription(string $token): bool
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->put($this->apiUrl()."/subscriptions/{$token}/cancel");

        return $response->successful();
    }

    /**
     * Pause a subscription via PayFast API.
     */
    public function pauseSubscription(string $token): bool
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->put($this->apiUrl()."/subscriptions/{$token}/pause");

        return $response->successful();
    }

    /**
     * Unpause a subscription via PayFast API.
     */
    public function unpauseSubscription(string $token): bool
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->put($this->apiUrl()."/subscriptions/{$token}/unpause");

        return $response->successful();
    }

    /**
     * Fetch subscription details from PayFast API.
     */
    public function fetchSubscription(string $token): array
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->get($this->apiUrl()."/subscriptions/{$token}/fetch");

        return $response->json() ?? [];
    }

    /**
     * Get the PayFast process URL for redirecting to checkout.
     */
    public function processUrl(): string
    {
        return $this->baseUrl().'/eng/process';
    }

    /**
     * Get the PayFast API base URL.
     */
    public function apiUrl(): string
    {
        return 'https://api.payfast.co.za';
    }

    /**
     * Get the base URL (sandbox or production).
     */
    protected function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://sandbox.payfast.co.za'
            : 'https://www.payfast.co.za';
    }

    /**
     * Get headers required for PayFast API calls.
     */
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/PayFastClientTest.php -v 2>&1 | tail -15`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/PayFastClient.php tests/Unit/PayFastClientTest.php
git commit -m "feat: add PayFastClient with signature generation and IP validation"
```

---

## Task 7: Core Models — Namespace Rename & Column Updates

**Files:**
- Modify: `src/Customer.php`
- Modify: `src/Subscription.php`
- Modify: `src/SubscriptionItem.php`
- Modify: `src/Transaction.php`
- Modify: `src/Payment.php`

- [ ] **Step 1: Rewrite Customer.php**

Replace `src/Customer.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

    public function billable()
    {
        return $this->morphTo();
    }

    /**
     * Determine if the customer is on a "generic" trial at the model level.
     */
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the customer has an expired "generic" trial at the model level.
     */
    public function hasExpiredGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }
}
```

- [ ] **Step 2: Rewrite Subscription.php**

Replace `src/Subscription.php`. Key changes: namespace, `paddle_id` → `provider_id`, remove Paddle API calls, add `frequency`/`cycles`, use `PayFastClient` for subscription management:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use HandycatsDev\CashierPayFast\Concerns\Prorates;

class Subscription extends Model
{
    use Prorates;

    const STATUS_ACTIVE = 'active';
    const STATUS_TRIALING = 'trialing';
    const STATUS_PAST_DUE = 'past_due';
    const STATUS_PAUSED = 'paused';
    const STATUS_CANCELED = 'canceled';

    const FREQUENCY_MONTHLY = 3;
    const FREQUENCY_QUARTERLY = 4;
    const FREQUENCY_BIANNUALLY = 5;
    const FREQUENCY_ANNUALLY = 6;

    const DEFAULT_TYPE = 'default';

    protected $guarded = [];

    protected $with = ['items'];

    protected $casts = [
        'frequency' => 'integer',
        'cycles' => 'integer',
        'trial_ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function billable()
    {
        return $this->morphTo();
    }

    public function items()
    {
        return $this->hasMany(Cashier::$subscriptionItemModel);
    }

    public function findItemOrFail($price)
    {
        return $this->items()->where('price_id', $price)->firstOrFail();
    }

    public function transactions()
    {
        return $this->hasMany(Cashier::$transactionModel, 'provider_subscription_id', 'provider_id')
            ->orderByDesc('created_at');
    }

    public function hasMultiplePrices(): bool
    {
        return $this->items->count() > 1;
    }

    public function hasSinglePrice(): bool
    {
        return ! $this->hasMultiplePrices();
    }

    public function hasProduct($product): bool
    {
        return $this->items->contains(fn (SubscriptionItem $item) => $item->product_id === $product);
    }

    public function hasPrice($price): bool
    {
        return $this->items->contains(fn (SubscriptionItem $item) => $item->price_id === $price);
    }

    // --- Status checks ---

    public function valid(): bool
    {
        return $this->onTrial() || $this->active() || (! Cashier::$deactivatePastDue && $this->pastDue());
    }

    public function scopeValid($query)
    {
        $query->where('status', self::STATUS_TRIALING)
            ->orWhere('status', self::STATUS_ACTIVE);

        if (! Cashier::$deactivatePastDue) {
            $query->orWhere('status', self::STATUS_PAST_DUE);
        }
    }

    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING;
    }

    public function scopeOnTrial($query)
    {
        $query->where('status', self::STATUS_TRIALING);
    }

    public function hasExpiredTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function scopeExpiredTrial($query)
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', Carbon::now());
    }

    public function scopeNotOnTrial($query)
    {
        $query->where('status', '!=', self::STATUS_TRIALING);
    }

    public function active(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive($query)
    {
        $query->where('status', '=', self::STATUS_ACTIVE);
    }

    public function recurring(): bool
    {
        return $this->active() && ! $this->onPausedGracePeriod() && ! $this->onGracePeriod();
    }

    public function scopeRecurring($query)
    {
        $query->active()->notOnPausedGracePeriod()->notOnGracePeriod();
    }

    public function pastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    public function scopePastDue($query)
    {
        $query->where('status', self::STATUS_PAST_DUE);
    }

    public function paused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function scopePaused($query)
    {
        $query->where('status', self::STATUS_PAUSED);
    }

    public function scopeNotPaused($query)
    {
        $query->where('status', '!=', self::STATUS_PAUSED);
    }

    public function onPausedGracePeriod(): bool
    {
        return $this->paused_at && $this->paused_at->isFuture();
    }

    public function scopeOnPausedGracePeriod($query)
    {
        $query->whereNotNull('paused_at')->where('paused_at', '>', Carbon::now());
    }

    public function scopeNotOnPausedGracePeriod($query)
    {
        $query->whereNull('paused_at')->orWhere('paused_at', '<=', Carbon::now());
    }

    public function canceled(): bool
    {
        return $this->status === self::STATUS_CANCELED;
    }

    public function scopeCanceled($query)
    {
        $query->where('status', self::STATUS_CANCELED);
    }

    public function scopeNotCanceled($query)
    {
        $query->where('status', '!=', self::STATUS_CANCELED);
    }

    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    public function scopeOnGracePeriod($query)
    {
        $query->whereNotNull('ends_at')->where('ends_at', '>', Carbon::now());
    }

    public function scopeNotOnGracePeriod($query)
    {
        $query->whereNull('ends_at')->orWhere('ends_at', '<=', Carbon::now());
    }

    // --- Subscription management via PayFast API ---

    /**
     * Pause the subscription via PayFast.
     */
    public function pause(): static
    {
        $client = app(PayFastClient::class);
        $client->pauseSubscription($this->provider_id);

        $this->forceFill([
            'status' => self::STATUS_PAUSED,
            'paused_at' => now(),
        ])->save();

        return $this;
    }

    /**
     * Resume a paused subscription via PayFast.
     */
    public function resume(): static
    {
        $client = app(PayFastClient::class);
        $client->unpauseSubscription($this->provider_id);

        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'paused_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Cancel the subscription via PayFast.
     */
    public function cancel(): static
    {
        $client = app(PayFastClient::class);
        $client->cancelSubscription($this->provider_id);

        $this->forceFill([
            'status' => self::STATUS_CANCELED,
            'ends_at' => now(),
        ])->save();

        return $this;
    }

    /**
     * Get the last payment for the subscription.
     */
    public function lastPayment(): ?Payment
    {
        if ($transaction = $this->transactions()->orderByDesc('billed_at')->first()) {
            return new Payment($transaction->amount_gross, $transaction->currency, $transaction->billed_at);
        }

        return null;
    }
}
```

- [ ] **Step 3: Rewrite SubscriptionItem.php**

Replace `src/SubscriptionItem.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Database\Eloquent\Model;

class SubscriptionItem extends Model
{
    protected $guarded = [];

    public function subscription()
    {
        return $this->belongsTo(Cashier::$subscriptionModel);
    }
}
```

- [ ] **Step 4: Rewrite Transaction.php**

Replace `src/Transaction.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Database\Eloquent\Model;
use Money\Currency;

class Transaction extends Model
{
    const STATUS_COMPLETE = 'COMPLETE';
    const STATUS_FAILED = 'FAILED';
    const STATUS_PENDING = 'PENDING';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $guarded = [];

    protected $casts = [
        'amount_gross' => 'decimal:2',
        'amount_fee' => 'decimal:2',
        'amount_net' => 'decimal:2',
        'billed_at' => 'datetime',
    ];

    public function billable()
    {
        return $this->morphTo();
    }

    public function subscription()
    {
        return $this->belongsTo(Cashier::$subscriptionModel, 'provider_subscription_id', 'provider_id');
    }

    /**
     * Get the formatted gross amount.
     */
    public function total(): string
    {
        return Cashier::formatAmount((int) ($this->amount_gross * 100), $this->currency());
    }

    /**
     * Get the formatted fee amount.
     */
    public function fee(): string
    {
        return Cashier::formatAmount((int) ($this->amount_fee * 100), $this->currency());
    }

    /**
     * Get the formatted net amount.
     */
    public function net(): string
    {
        return Cashier::formatAmount((int) ($this->amount_net * 100), $this->currency());
    }

    public function currency(): Currency
    {
        return new Currency($this->currency ?? 'ZAR');
    }
}
```

- [ ] **Step 5: Rewrite Payment.php**

Replace `src/Payment.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use Money\Currency;

class Payment implements Arrayable, Jsonable, JsonSerializable
{
    public function __construct(
        public $amount,
        public $currency,
        public $date
    ) {
    }

    public function amount(): string
    {
        return Cashier::formatAmount((int) ($this->amount * 100), $this->currency);
    }

    public function rawAmount()
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return new Currency($this->currency);
    }

    public function date(): Carbon
    {
        return $this->date;
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount(),
            'currency' => $this->currency,
            'date' => $this->date()->toIso8601String(),
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Customer.php src/Subscription.php src/SubscriptionItem.php src/Transaction.php src/Payment.php
git commit -m "feat: rewrite core models for PayFast (provider_id, amounts, frequency)"
```

---

## Task 8: Cashier Core Class Rewrite

**Files:**
- Modify: `src/Cashier.php`

- [ ] **Step 1: Rewrite Cashier.php**

Replace `src/Cashier.php`. Remove Paddle API methods, keep formatting and model management:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;

class Cashier
{
    const VERSION = '1.0.0';

    protected static $formatCurrencyUsing;

    public static bool $registersRoutes = true;

    public static bool $deactivatePastDue = true;

    public static string $customerModel = Customer::class;

    public static string $subscriptionModel = Subscription::class;

    public static string $subscriptionItemModel = SubscriptionItem::class;

    public static string $transactionModel = Transaction::class;

    /**
     * Get the customer instance by its provider ID.
     */
    public static function findBillable($providerId)
    {
        return (new static::$customerModel)->where('provider_id', $providerId)->first()?->billable;
    }

    /**
     * Get the webhook URL.
     */
    public static function webhookUrl(): string
    {
        return config('cashier.notify_url') ?? route('cashier.webhook');
    }

    /**
     * Set the custom currency formatter.
     */
    public static function formatCurrencyUsing(callable $callback): void
    {
        static::$formatCurrencyUsing = $callback;
    }

    /**
     * Format the given amount into a displayable currency.
     */
    public static function formatAmount($amount, $currency, $locale = null, array $options = []): string
    {
        if (static::$formatCurrencyUsing) {
            return call_user_func(static::$formatCurrencyUsing, $amount, $currency, $locale, $options);
        }

        $money = new Money($amount, new Currency(strtoupper($currency)));

        $locale = $locale ?? config('cashier.currency_locale');

        $numberFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        if (isset($options['min_fraction_digits'])) {
            $numberFormatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $options['min_fraction_digits']);
        }

        $moneyFormatter = new IntlMoneyFormatter($numberFormatter, new ISOCurrencies());

        return $moneyFormatter->format($money);
    }

    public static function ignoreRoutes(): static
    {
        static::$registersRoutes = false;

        return new static;
    }

    public static function keepPastDueSubscriptionsActive(): static
    {
        static::$deactivatePastDue = false;

        return new static;
    }

    public static function useCustomerModel($customerModel): void
    {
        static::$customerModel = $customerModel;
    }

    public static function useSubscriptionModel($subscriptionModel): void
    {
        static::$subscriptionModel = $subscriptionModel;
    }

    public static function useSubscriptionItemModel($subscriptionItemModel): void
    {
        static::$subscriptionItemModel = $subscriptionItemModel;
    }

    public static function useTransactionModel($transactionModel): void
    {
        static::$transactionModel = $transactionModel;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Cashier.php
git commit -m "feat: rewrite Cashier core class for PayFast (remove Paddle API)"
```

---

## Task 9: Concerns — Namespace & Logic Updates

**Files:**
- Modify: `src/Concerns/ManagesCustomer.php`
- Modify: `src/Concerns/ManagesSubscriptions.php`
- Modify: `src/Concerns/ManagesTransactions.php`
- Modify: `src/Concerns/Prorates.php`
- Delete: `src/Concerns/PerformsCharges.php`
- Modify: `src/Billable.php`

- [ ] **Step 1: Rewrite ManagesCustomer.php**

Replace `src/Concerns/ManagesCustomer.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Concerns;

use HandycatsDev\CashierPayFast\Cashier;

trait ManagesCustomer
{
    /**
     * Create a customer record for the given model.
     */
    public function createAsCustomer(array $options = [])
    {
        if ($customer = $this->customer) {
            return $customer;
        }

        if (! array_key_exists('name', $options) && $name = $this->providerName()) {
            $options['name'] = $name;
        }

        if (! array_key_exists('email', $options) && $email = $this->providerEmail()) {
            $options['email'] = $email;
        }

        $trialEndsAt = $options['trial_ends_at'] ?? null;
        unset($options['trial_ends_at']);

        $customer = $this->customer()->make();
        $customer->provider_id = $options['provider_id'] ?? '';
        $customer->name = $options['name'] ?? '';
        $customer->email = $options['email'] ?? '';
        $customer->trial_ends_at = $trialEndsAt;
        $customer->save();

        $this->refresh();

        return $customer;
    }

    /**
     * Get the customer related to the billable model.
     */
    public function customer()
    {
        return $this->morphOne(Cashier::$customerModel, 'billable');
    }

    /**
     * Get the billable model's name to associate with the provider.
     */
    public function providerName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the billable model's email address to associate with the provider.
     */
    public function providerEmail(): ?string
    {
        return $this->email;
    }
}
```

- [ ] **Step 2: Rewrite ManagesSubscriptions.php**

Replace `src/Concerns/ManagesSubscriptions.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Concerns;

use HandycatsDev\CashierPayFast\Cashier;
use HandycatsDev\CashierPayFast\Subscription;
use HandycatsDev\CashierPayFast\SubscriptionBuilder;

trait ManagesSubscriptions
{
    public function subscriptions()
    {
        return $this->morphMany(Cashier::$subscriptionModel, 'billable')->orderByDesc('created_at');
    }

    public function subscription($type = 'default')
    {
        return $this->subscriptions->where('type', $type)->first();
    }

    /**
     * Begin building a new subscription.
     */
    public function newSubscription(int $amount, string $name, string $type = Subscription::DEFAULT_TYPE): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $amount, $name, $type);
    }

    public function onTrial($type = 'default', $price = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->onTrial()) {
            return false;
        }

        return $price ? $subscription->hasPrice($price) : true;
    }

    public function hasExpiredTrial($type = 'default', $price = null): bool
    {
        if (func_num_args() === 0 && $this->hasExpiredGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->hasExpiredTrial()) {
            return false;
        }

        return $price ? $subscription->hasPrice($price) : true;
    }

    public function onGenericTrial(): bool
    {
        if (is_null($this->customer)) {
            return false;
        }

        return $this->customer->onGenericTrial();
    }

    public function hasExpiredGenericTrial(): bool
    {
        if (is_null($this->customer)) {
            return false;
        }

        return $this->customer->hasExpiredGenericTrial();
    }

    public function trialEndsAt($type = 'default')
    {
        if (is_null($this->customer)) {
            return null;
        }

        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return $this->customer->trial_ends_at;
        }

        if ($subscription = $this->subscription($type)) {
            return $subscription->trial_ends_at;
        }

        return $this->customer->trial_ends_at;
    }

    public function subscribed($type = 'default', $price = null): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $price ? $subscription->hasPrice($price) : true;
    }

    public function subscribedToProduct($products, $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $products as $product) {
            if ($subscription->hasProduct($product)) {
                return true;
            }
        }

        return false;
    }

    public function subscribedToPrice($prices, $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $prices as $price) {
            if ($subscription->hasPrice($price)) {
                return true;
            }
        }

        return false;
    }

    public function onProduct($product): bool
    {
        return ! is_null($this->subscriptions->first(function (Subscription $subscription) use ($product) {
            return $subscription->valid() && $subscription->hasProduct($product);
        }));
    }

    public function onPrice($price): bool
    {
        return ! is_null($this->subscriptions->first(function (Subscription $subscription) use ($price) {
            return $subscription->valid() && $subscription->hasPrice($price);
        }));
    }
}
```

- [ ] **Step 3: Rewrite ManagesTransactions.php**

Replace `src/Concerns/ManagesTransactions.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Concerns;

use HandycatsDev\CashierPayFast\Cashier;

trait ManagesTransactions
{
    public function transactions()
    {
        return $this->morphMany(Cashier::$transactionModel, 'billable')->orderByDesc('billed_at');
    }
}
```

- [ ] **Step 4: Update Prorates.php namespace**

Replace `src/Concerns/Prorates.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Concerns;

trait Prorates
{
    protected $prorationBehavior = 'prorated_next_billing_period';

    public function prorate()
    {
        $this->prorationBehavior = 'prorated_next_billing_period';

        return $this;
    }

    public function noProrate()
    {
        $this->prorationBehavior = 'full_next_billing_period';

        return $this;
    }

    public function prorateImmediately()
    {
        $this->prorationBehavior = 'prorated_immediately';

        return $this;
    }

    public function immediatelyWithoutProrate()
    {
        $this->prorationBehavior = 'full_immediately';

        return $this;
    }

    public function doNotBill()
    {
        $this->prorationBehavior = 'do_not_bill';

        return $this;
    }

    public function setProrationBehavior($prorationBehavior)
    {
        $this->prorationBehavior = $prorationBehavior;

        return $this;
    }
}
```

- [ ] **Step 5: Delete PerformsCharges.php**

```bash
rm src/Concerns/PerformsCharges.php
```

- [ ] **Step 6: Rewrite Billable.php**

Replace `src/Billable.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use HandycatsDev\CashierPayFast\Concerns\ManagesCustomer;
use HandycatsDev\CashierPayFast\Concerns\ManagesSubscriptions;
use HandycatsDev\CashierPayFast\Concerns\ManagesTransactions;

trait Billable
{
    use ManagesCustomer;
    use ManagesSubscriptions;
    use ManagesTransactions;
}
```

- [ ] **Step 7: Commit**

```bash
git add src/Concerns/ src/Billable.php
git commit -m "feat: rewrite concerns and Billable trait for PayFast"
```

---

## Task 10: Checkout & SubscriptionBuilder Rewrite

**Files:**
- Modify: `src/Checkout.php`
- Modify: `src/SubscriptionBuilder.php`

- [ ] **Step 1: Rewrite Checkout.php for redirect-based flow**

Replace `src/Checkout.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\RedirectResponse;
use JsonSerializable;

class Checkout implements Arrayable, JsonSerializable
{
    protected array $custom = [];

    protected ?string $returnUrl = null;

    protected ?string $cancelUrl = null;

    protected ?string $notifyUrl = null;

    public function __construct(
        protected PayFastClient $client,
        protected array $params = []
    ) {
        $this->returnUrl = config('cashier.return_url');
        $this->cancelUrl = config('cashier.cancel_url');
        $this->notifyUrl = config('cashier.notify_url');
    }

    /**
     * Create a checkout for a one-time payment.
     */
    public static function make(array $params = []): static
    {
        return new static(app(PayFastClient::class), $params);
    }

    /**
     * Set the return URL.
     */
    public function returnTo(string $url): static
    {
        $this->returnUrl = $url;

        return $this;
    }

    /**
     * Set the cancel URL.
     */
    public function cancelTo(string $url): static
    {
        $this->cancelUrl = $url;

        return $this;
    }

    /**
     * Set the ITN notify URL.
     */
    public function notifyTo(string $url): static
    {
        $this->notifyUrl = $url;

        return $this;
    }

    /**
     * Add custom data to the checkout.
     */
    public function customData(array $custom): static
    {
        $this->custom = array_merge($this->custom, $custom);

        return $this;
    }

    /**
     * Get the full payment form fields including signature.
     */
    public function fields(): array
    {
        $data = array_merge($this->params, array_filter([
            'return_url' => $this->returnUrl,
            'cancel_url' => $this->cancelUrl,
            'notify_url' => $this->notifyUrl,
        ]));

        if (! empty($this->custom)) {
            $data['custom_str1'] = json_encode($this->custom);
        }

        return $this->client->buildPaymentData($data);
    }

    /**
     * Get the PayFast process URL.
     */
    public function url(): string
    {
        return $this->client->processUrl();
    }

    /**
     * Create a redirect response to PayFast.
     */
    public function redirect(): RedirectResponse
    {
        // Build a self-submitting form via a redirect with the form data
        return new RedirectResponse($this->url().'?'.http_build_query($this->fields()));
    }

    public function getCustomData(): array
    {
        return $this->custom;
    }

    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function jsonSerialize(): mixed
    {
        return $this->fields();
    }

    public function toArray(): array
    {
        return $this->fields();
    }
}
```

- [ ] **Step 2: Rewrite SubscriptionBuilder.php**

Replace `src/SubscriptionBuilder.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

class SubscriptionBuilder
{
    protected int $quantity = 1;

    protected int $frequency = Subscription::FREQUENCY_MONTHLY;

    protected int $cycles = 0;

    public function __construct(
        protected $billable,
        protected int $amount,
        protected string $name,
        protected string $type = Subscription::DEFAULT_TYPE
    ) {
    }

    public function quantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function monthly(): static
    {
        $this->frequency = Subscription::FREQUENCY_MONTHLY;

        return $this;
    }

    public function quarterly(): static
    {
        $this->frequency = Subscription::FREQUENCY_QUARTERLY;

        return $this;
    }

    public function biannually(): static
    {
        $this->frequency = Subscription::FREQUENCY_BIANNUALLY;

        return $this;
    }

    public function yearly(): static
    {
        $this->frequency = Subscription::FREQUENCY_ANNUALLY;

        return $this;
    }

    /**
     * Set the number of billing cycles (0 = indefinite).
     */
    public function cycles(int $cycles): static
    {
        $this->cycles = $cycles;

        return $this;
    }

    /**
     * Get a Checkout instance configured for recurring billing.
     */
    public function checkout(array $options = []): Checkout
    {
        $params = array_merge([
            'amount' => number_format($this->amount / 100, 2, '.', ''),
            'item_name' => $this->name,
            'subscription_type' => 1,
            'frequency' => $this->frequency,
            'cycles' => $this->cycles,
        ], $options);

        return Checkout::make($params)->customData([
            'subscription_type' => $this->type,
        ]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Checkout.php src/SubscriptionBuilder.php
git commit -m "feat: rewrite Checkout and SubscriptionBuilder for PayFast redirect flow"
```

---

## Task 11: Webhook Controller & Middleware Rewrite

**Files:**
- Modify: `src/Http/Controllers/WebhookController.php`
- Modify: `src/Http/Middleware/VerifyWebhookSignature.php`

- [ ] **Step 1: Rewrite VerifyWebhookSignature.php for PayFast ITN**

Replace `src/Http/Middleware/VerifyWebhookSignature.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Http\Middleware;

use Closure;
use HandycatsDev\CashierPayFast\PayFastClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next)
    {
        $client = app(PayFastClient::class);

        // Step 1: Verify IP
        if (! $client->isValidIp($request->ip())) {
            throw new AccessDeniedHttpException('Invalid ITN source IP.');
        }

        // Step 2: Verify signature
        if (! $client->validateSignature($request->all())) {
            throw new AccessDeniedHttpException('Invalid ITN signature.');
        }

        // Step 3: Confirm with PayFast server
        if (! $client->confirmItn($request->except('signature'))) {
            throw new AccessDeniedHttpException('ITN server confirmation failed.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Rewrite WebhookController.php for PayFast ITN**

Replace `src/Http/Controllers/WebhookController.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use HandycatsDev\CashierPayFast\Cashier;
use HandycatsDev\CashierPayFast\Events\PaymentComplete;
use HandycatsDev\CashierPayFast\Events\PaymentFailed;
use HandycatsDev\CashierPayFast\Events\SubscriptionCanceled;
use HandycatsDev\CashierPayFast\Events\SubscriptionCreated;
use HandycatsDev\CashierPayFast\Events\WebhookHandled;
use HandycatsDev\CashierPayFast\Events\WebhookReceived;
use HandycatsDev\CashierPayFast\Http\Middleware\VerifyWebhookSignature;
use HandycatsDev\CashierPayFast\Subscription;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct()
    {
        if (config('cashier.passphrase')) {
            $this->middleware(VerifyWebhookSignature::class);
        }
    }

    public function __invoke(Request $request)
    {
        $payload = $request->all();

        WebhookReceived::dispatch($payload);

        $status = $payload['payment_status'] ?? null;

        match ($status) {
            'COMPLETE' => $this->handleComplete($payload),
            'FAILED' => $this->handleFailed($payload),
            'CANCELLED' => $this->handleCancelled($payload),
            default => null,
        };

        WebhookHandled::dispatch($payload);

        return new Response('OK', 200);
    }

    protected function handleComplete(array $payload): void
    {
        $billable = $this->findOrCreateBillable($payload);

        if (! $billable) {
            return;
        }

        // Create the transaction record
        $transaction = $billable->transactions()->create([
            'provider_id' => $payload['pf_payment_id'],
            'provider_subscription_id' => $payload['token'] ?? null,
            'payment_status' => $payload['payment_status'],
            'amount_gross' => $payload['amount_gross'],
            'amount_fee' => $payload['amount_fee'],
            'amount_net' => $payload['amount_net'],
            'currency' => 'ZAR',
            'billed_at' => now(),
        ]);

        PaymentComplete::dispatch($billable, $transaction, $payload);

        // If this is a subscription payment, create/update the subscription
        if (! empty($payload['token'])) {
            $this->handleSubscriptionPayment($billable, $payload);
        }
    }

    protected function handleFailed(array $payload): void
    {
        $billable = $this->findBillableByEmail($payload['email_address'] ?? '');

        PaymentFailed::dispatch($billable, $payload);
    }

    protected function handleCancelled(array $payload): void
    {
        if (empty($payload['token'])) {
            return;
        }

        $subscription = Cashier::$subscriptionModel::where('provider_id', $payload['token'])->first();

        if (! $subscription) {
            return;
        }

        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELED,
            'ends_at' => now(),
        ])->save();

        SubscriptionCanceled::dispatch($subscription, $payload);
    }

    protected function handleSubscriptionPayment($billable, array $payload): void
    {
        $token = $payload['token'];

        $subscription = Cashier::$subscriptionModel::where('provider_id', $token)->first();

        if (! $subscription) {
            // First payment — create the subscription
            $customData = json_decode($payload['custom_str1'] ?? '{}', true);

            $subscription = $billable->subscriptions()->create([
                'type' => $customData['subscription_type'] ?? Subscription::DEFAULT_TYPE,
                'provider_id' => $token,
                'status' => Subscription::STATUS_ACTIVE,
                'frequency' => $payload['frequency'] ?? null,
                'cycles' => $payload['cycles'] ?? 0,
            ]);

            $billable->customer?->update(['trial_ends_at' => null]);

            SubscriptionCreated::dispatch($billable, $subscription, $payload);
        } else {
            // Recurring payment — ensure subscription is active
            if ($subscription->status !== Subscription::STATUS_ACTIVE) {
                $subscription->forceFill([
                    'status' => Subscription::STATUS_ACTIVE,
                ])->save();
            }
        }
    }

    protected function findOrCreateBillable(array $payload)
    {
        $email = $payload['email_address'] ?? '';

        // Try to find by subscription token first
        if (! empty($payload['token'])) {
            $subscription = Cashier::$subscriptionModel::where('provider_id', $payload['token'])->first();
            if ($subscription) {
                return $subscription->billable;
            }
        }

        return $this->findBillableByEmail($email);
    }

    protected function findBillableByEmail(string $email)
    {
        if (empty($email)) {
            return null;
        }

        return Cashier::$customerModel::where('email', $email)->first()?->billable;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Http/
git commit -m "feat: rewrite webhook controller and middleware for PayFast ITN"
```

---

## Task 12: Service Provider & Blade Components

**Files:**
- Modify: `src/CashierServiceProvider.php`
- Modify: `src/Components/Button.php`
- Modify: `src/Components/Checkout.php`
- Modify: `resources/views/js.blade.php`
- Modify: `resources/views/components/button.blade.php`
- Modify: `resources/views/components/checkout.blade.php`

- [ ] **Step 1: Rewrite CashierServiceProvider.php**

Replace `src/CashierServiceProvider.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use HandycatsDev\CashierPayFast\Components\Button;
use HandycatsDev\CashierPayFast\Components\Checkout as CheckoutComponent;

class CashierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cashier.php', 'cashier'
        );

        $this->app->singleton(PayFastClient::class, function ($app) {
            return new PayFastClient(
                merchantId: config('cashier.merchant_id', ''),
                merchantKey: config('cashier.merchant_key', ''),
                passphrase: config('cashier.passphrase', ''),
                sandbox: config('cashier.sandbox', false),
            );
        });
    }

    public function boot(): void
    {
        $this->bootRoutes();
        $this->bootResources();
        $this->bootPublishing();
        $this->bootDirectives();
        $this->bootComponents();
    }

    protected function bootRoutes(): void
    {
        if (Cashier::$registersRoutes) {
            Route::group([
                'prefix' => config('cashier.path'),
                'namespace' => 'HandycatsDev\CashierPayFast\Http\Controllers',
                'as' => 'cashier.',
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        }
    }

    protected function bootResources(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cashier');
    }

    protected function bootPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cashier.php' => $this->app->configPath('cashier.php'),
            ], 'cashier-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'cashier-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/cashier'),
            ], 'cashier-views');
        }
    }

    protected function bootDirectives(): void
    {
        Blade::directive('cashierJS', function ($expression) {
            $expression = $expression ?: '[]';

            return '<?php echo view("cashier::js", ["nonce" => '.$expression.'["nonce"] ?? ""]); ?>';
        });
    }

    protected function bootComponents(): void
    {
        Blade::component(Button::class, 'cashier-button');
        Blade::component(CheckoutComponent::class, 'cashier-checkout');
    }
}
```

- [ ] **Step 2: Rewrite Button component**

Replace `src/Components/Button.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Components;

use HandycatsDev\CashierPayFast\Checkout;
use Illuminate\View\Component;

class Button extends Component
{
    public function __construct(
        public Checkout $checkout
    ) {
    }

    public function render()
    {
        return view('cashier::components.button');
    }
}
```

- [ ] **Step 3: Rewrite Checkout component**

Replace `src/Components/Checkout.php`:

```php
<?php

namespace HandycatsDev\CashierPayFast\Components;

use HandycatsDev\CashierPayFast\Checkout as CheckoutInstance;
use Illuminate\View\Component;

class Checkout extends Component
{
    public function __construct(
        public CheckoutInstance $checkout,
        public string $id = 'cashier-checkout-form'
    ) {
    }

    public function fields(): array
    {
        return $this->checkout->fields();
    }

    public function action(): string
    {
        return $this->checkout->url();
    }

    public function render()
    {
        return view('cashier::components.checkout');
    }
}
```

- [ ] **Step 4: Rewrite Blade views**

Replace `resources/views/js.blade.php`:

```blade
<?php $nonce = $nonce ?? ''; ?>

<script @if ($nonce) nonce="{{ $nonce }}" @endif>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form.cashier-checkout-form').forEach(function(form) {
            if (form.dataset.autoSubmit === 'true') {
                form.submit();
            }
        });
    });
</script>
```

Replace `resources/views/components/button.blade.php`:

```blade
<form method="POST" action="{{ $checkout->url() }}" class="cashier-checkout-form" style="display: inline;">
    @foreach ($checkout->fields() as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    <button {{ $attributes->merge(['type' => 'submit']) }}>
        {{ $slot }}
    </button>
</form>
```

Replace `resources/views/components/checkout.blade.php`:

```blade
<form method="POST" action="{{ $action() }}" id="{{ $id }}" class="cashier-checkout-form" data-auto-submit="true" {{ $attributes }}>
    @foreach ($fields() as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    {{ $slot }}
</form>
```

- [ ] **Step 5: Commit**

```bash
git add src/CashierServiceProvider.php src/Components/ resources/views/
git commit -m "feat: rewrite service provider and Blade components for PayFast"
```

---

## Task 13: Remove Deleted Classes

**Files:**
- Delete: `src/Price.php`
- Delete: `src/PricePreview.php`
- Delete: `src/CashierFake.php`

- [ ] **Step 1: Remove files that have no PayFast equivalent**

```bash
rm src/Price.php src/PricePreview.php src/CashierFake.php
```

- [ ] **Step 2: Commit**

```bash
git add -A src/Price.php src/PricePreview.php src/CashierFake.php
git commit -m "chore: remove Paddle-only classes (Price, PricePreview, CashierFake)"
```

---

## Task 14: Update Routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Verify routes/web.php is correct**

Replace `routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::post('webhook', 'WebhookController')->name('cashier.webhook');
```

This file is likely already correct — the namespace is set by the service provider. Just verify it matches.

- [ ] **Step 2: Commit if changed**

```bash
git add routes/web.php
git commit -m "chore: verify webhook route" --allow-empty
```

---

## Task 15: Update Workbench & Test Infrastructure

**Files:**
- Modify: `workbench/app/Models/User.php`
- Modify: `tests/TestCase.php`

- [ ] **Step 1: Update workbench User model**

Replace `workbench/app/Models/User.php`:

```php
<?php

namespace Workbench\App\Models;

use HandycatsDev\CashierPayFast\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;

    protected $guarded = [];
}
```

- [ ] **Step 2: Update TestCase.php**

Replace `tests/TestCase.php`:

```php
<?php

namespace Tests;

use HandycatsDev\CashierPayFast\CashierServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [CashierServiceProvider::class];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add workbench/ tests/TestCase.php
git commit -m "chore: update test infrastructure for new namespace"
```

---

## Task 16: Webhook Integration Test

**Files:**
- Create: `tests/Feature/WebhookTest.php`

- [ ] **Step 1: Write the webhook integration test**

Create `tests/Feature/WebhookTest.php`:

```php
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

        // Mock the PayFastClient to skip real IP/signature/server checks
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
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/phpunit tests/Feature/WebhookTest.php -v 2>&1 | tail -20`
Expected: All tests PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/WebhookTest.php
git commit -m "test: add webhook integration tests for PayFast ITN handling"
```

---

## Task 17: Checkout Unit Test

**Files:**
- Create: `tests/Unit/CheckoutTest.php`

- [ ] **Step 1: Write checkout unit test**

Create `tests/Unit/CheckoutTest.php`:

```php
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

        // We need to test without Laravel's app() container
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
```

- [ ] **Step 2: Run tests**

Run: `vendor/bin/phpunit tests/Unit/CheckoutTest.php -v 2>&1 | tail -10`
Expected: All tests PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/CheckoutTest.php
git commit -m "test: add Checkout unit tests"
```

---

## Task 18: Clean Up Old Tests

**Files:**
- Modify/Delete: existing test files that reference `Laravel\Paddle`

- [ ] **Step 1: Remove or rewrite old Paddle-specific tests**

Delete test files that are tightly coupled to Paddle's API and can't be adapted:

```bash
rm -f tests/Feature/CustomerTest.php tests/Feature/SubscriptionsTest.php tests/Feature/TransactionsTest.php tests/Feature/WebhooksTest.php tests/Feature/PricesTest.php tests/Feature/CashierFakeTest.php tests/Feature/FeatureTestCase.php
rm -f tests/Unit/CashierTest.php tests/Unit/CustomerTest.php tests/Unit/SubscriptionTest.php tests/Unit/PricePreviewTest.php
```

- [ ] **Step 2: Commit**

```bash
git add -A tests/
git commit -m "chore: remove Paddle-specific tests, keep new PayFast tests"
```

---

## Task 19: Run Full Test Suite & Fix Issues

- [ ] **Step 1: Run composer dump-autoload**

Run: `composer dump-autoload --no-interaction 2>&1`
Expected: Clean autoload generation

- [ ] **Step 2: Run full test suite**

Run: `vendor/bin/phpunit 2>&1`
Expected: All tests pass. If failures occur, diagnose and fix each one.

- [ ] **Step 3: Run PHPStan if available**

Run: `vendor/bin/phpstan analyse --no-progress 2>&1 | tail -20`
Expected: Review for any critical type errors. Fix namespace-related issues.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix: resolve test and static analysis issues"
```

---

## Task 20: Clean Up Documentation & Boost Skill

**Files:**
- Modify: `README.md`
- Delete: `resources/boost/` directory (Paddle-specific skill)

- [ ] **Step 1: Remove the Paddle boost skill**

```bash
rm -rf resources/boost/
```

- [ ] **Step 2: Update README.md header**

Update the first few lines of `README.md` to reference PayFast instead of Paddle. Keep it brief — a full docs rewrite is a separate task.

Change the title and first paragraph to:

```markdown
# Cashier PayFast

Laravel Cashier integration for PayFast. Provides an expressive, fluent interface to PayFast's subscription billing services.
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore: clean up Paddle references from docs and boost skill"
```
