# Cashier PayFast - Design Spec

## Overview

Fork of `laravel/cashier-paddle` rebranded and rewritten to integrate with PayFast instead of Paddle. Maintains Laravel Cashier's familiar developer interface while adapting to PayFast's redirect-based checkout and ITN webhook model.

**Package:** `HandycatsDev/cashier-payfast`
**Namespace:** `HandycatsDev\CashierPayFast`
**Target:** PayFast sandbox (`sandbox.payfast.co.za`) and production (`www.payfast.co.za`)

---

## 1. Naming & Namespace Convention

### Namespace

All classes move from `Laravel\Paddle` to `HandycatsDev\CashierPayFast`.

| Current | New |
|---|---|
| `Laravel\Paddle\Cashier` | `HandycatsDev\CashierPayFast\Cashier` |
| `Laravel\Paddle\Customer` | `HandycatsDev\CashierPayFast\Customer` |
| `Laravel\Paddle\Subscription` | `HandycatsDev\CashierPayFast\Subscription` |
| `Laravel\Paddle\Transaction` | `HandycatsDev\CashierPayFast\Transaction` |
| `Laravel\Paddle\SubscriptionItem` | `HandycatsDev\CashierPayFast\SubscriptionItem` |
| `Laravel\Paddle\SubscriptionBuilder` | `HandycatsDev\CashierPayFast\SubscriptionBuilder` |
| `Laravel\Paddle\Billable` | `HandycatsDev\CashierPayFast\Billable` |
| `Laravel\Paddle\Checkout` | `HandycatsDev\CashierPayFast\Checkout` |
| `Laravel\Paddle\Payment` | `HandycatsDev\CashierPayFast\Payment` |
| `Laravel\Paddle\CashierServiceProvider` | `HandycatsDev\CashierPayFast\CashierServiceProvider` |
| `Laravel\Paddle\Exceptions\PaddleException` | `HandycatsDev\CashierPayFast\Exceptions\PayFastException` |
| `Laravel\Paddle\Http\Controllers\WebhookController` | `HandycatsDev\CashierPayFast\Http\Controllers\WebhookController` |
| `Laravel\Paddle\Http\Middleware\VerifyWebhookSignature` | `HandycatsDev\CashierPayFast\Http\Middleware\VerifyWebhookSignature` |
| `Laravel\Paddle\Concerns\*` | `HandycatsDev\CashierPayFast\Concerns\*` |
| `Laravel\Paddle\Events\*` | `HandycatsDev\CashierPayFast\Events\*` |
| `Laravel\Paddle\Components\*` | `HandycatsDev\CashierPayFast\Components\*` |

### Composer Autoload

```json
{
  "psr-4": {
    "HandycatsDev\\CashierPayFast\\": "src/"
  }
}
```

### Database Columns

| Current | New |
|---|---|
| `paddle_id` | `provider_id` |
| `paddle_subscription_id` | `provider_subscription_id` |

### Environment Variables

| Current | New | PayFast Mapping |
|---|---|---|
| `PADDLE_SELLER_ID` | `CASHIER_MERCHANT_ID` | PayFast Merchant ID |
| `PADDLE_API_KEY` | `CASHIER_MERCHANT_KEY` | PayFast Merchant Key |
| `PADDLE_WEBHOOK_SECRET` | `CASHIER_PASSPHRASE` | PayFast Passphrase |
| `PADDLE_SANDBOX` | `CASHIER_SANDBOX` | Sandbox toggle |
| `PADDLE_CLIENT_SIDE_TOKEN` | Removed | No equivalent |
| `PADDLE_RETAIN_KEY` | Removed | No equivalent |

Additional new env vars:

| Variable | Purpose |
|---|---|
| `CASHIER_RETURN_URL` | Where users return after payment (default: `/cashier/return`) |
| `CASHIER_CANCEL_URL` | Where users go on payment cancel (default: `/cashier/cancel`) |
| `CASHIER_NOTIFY_URL` | ITN callback URL (default: `/cashier/webhook`) |

### Method Names

| Current | New |
|---|---|
| `paddleName()` | `providerName()` |
| `paddleEmail()` | `providerEmail()` |

### Blade Directives & Components

| Current | New |
|---|---|
| `@paddleJS` | `@cashierJS` |
| `<x-paddle-button>` | `<x-cashier-button>` |
| `<x-paddle-checkout>` | `<x-cashier-checkout>` |

### Route Path

Default route prefix changes from `paddle` to `cashier`.

---

## 2. PayFast Client & API Integration

### New Class: `PayFastClient`

Single point of contact for all PayFast HTTP communication. Located at `src/PayFastClient.php`.

#### Responsibilities

- Generate payment form data (merchant ID, key, amounts, signature)
- Build redirect URLs to PayFast's payment engine
- Validate ITN (Instant Transaction Notification) callbacks
- Subscription API calls (cancel, pause, unpause, fetch)
- MD5 signature generation and validation

#### Key Methods

```php
class PayFastClient
{
    public function buildPaymentData(array $params): array;
    public function validateITN(Request $request): bool;
    public function cancelSubscription(string $token): bool;
    public function pauseSubscription(string $token): bool;
    public function unpauseSubscription(string $token): bool;
    public function fetchSubscription(string $token): array;
}
```

#### Configuration (`config/cashier.php`)

```php
return [
    'merchant_id'  => env('CASHIER_MERCHANT_ID'),
    'merchant_key' => env('CASHIER_MERCHANT_KEY'),
    'passphrase'   => env('CASHIER_PASSPHRASE'),
    'sandbox'      => env('CASHIER_SANDBOX', false),
    'return_url'   => env('CASHIER_RETURN_URL', '/cashier/return'),
    'cancel_url'   => env('CASHIER_CANCEL_URL', '/cashier/cancel'),
    'notify_url'   => env('CASHIER_NOTIFY_URL', '/cashier/webhook'),
    'path'         => env('CASHIER_PATH', 'cashier'),
];
```

#### URL Resolution

| Environment | Payment URL | API URL |
|---|---|---|
| Production | `www.payfast.co.za/eng/process` | `api.payfast.co.za` |
| Sandbox | `sandbox.payfast.co.za/eng/process` | `api.payfast.co.za` (test credentials) |

#### Signature Generation

PayFast uses MD5 hashing of URL-encoded, alphabetically-sorted POST parameters with the passphrase appended:

```
signature = md5(urlencode(sorted_params) + "&passphrase=" + urlencode(passphrase))
```

---

## 3. Checkout Flow

PayFast uses a redirect-based checkout, not a JS widget.

### Flow

1. App builds form data with `Checkout` class (amount, item name, merchant details, return/cancel/notify URLs)
2. User is redirected to PayFast via auto-submitting HTML form
3. User completes payment on PayFast
4. PayFast redirects user back to `return_url`
5. PayFast sends ITN POST to `notify_url` to confirm payment

### `Checkout` Class Rework

```php
class Checkout
{
    public function redirect(): RedirectResponse;  // Returns redirect with form data
    public function fields(): array;                // Returns raw form data array
    public function url(): string;                  // Returns the PayFast process URL
}
```

### `SubscriptionBuilder` Rework

- `checkout()` returns a `Checkout` instance configured for recurring billing
- PayFast recurring billing uses `subscription_type` = 1
- Subscription frequency mapped to PayFast's `frequency` parameter:
  - 3 = Monthly
  - 4 = Quarterly
  - 5 = Biannually
  - 6 = Annually
- `cycles` parameter controls number of billing cycles (0 = indefinite)

### Blade Components

- `<x-cashier-checkout>` — renders a self-submitting form that POSTs to PayFast
- `<x-cashier-button>` — renders a button that submits the checkout form
- `@cashierJS` — minimal JS for form submission handling (no external SDK needed)

### URL Configuration

```php
'return_url' => env('CASHIER_RETURN_URL', '/cashier/return'),
'cancel_url' => env('CASHIER_CANCEL_URL', '/cashier/cancel'),
'notify_url' => env('CASHIER_NOTIFY_URL', '/cashier/webhook'),
```

---

## 4. Webhook / ITN Handling

PayFast's ITN replaces Paddle's webhook system.

### `WebhookController`

Receives POST requests from PayFast at the `notify_url`.

| ITN `payment_status` | Action |
|---|---|
| `COMPLETE` | Mark transaction complete, activate subscription if recurring |
| `FAILED` | Mark transaction failed, fire `PaymentFailed` event |
| `PENDING` | Mark transaction pending |
| `CANCELLED` | Cancel subscription, fire `SubscriptionCancelled` event |

### `VerifyWebhookSignature` Middleware

PayFast ITN validation is a 3-step process (all required):

1. **IP verification** — confirm request comes from PayFast's known IP ranges (`197.97.145.144/28` and `41.74.179.192/27`)
2. **Signature verification** — MD5 hash of sorted POST params + passphrase matches the `signature` field
3. **Server confirmation** — POST the data back to PayFast at `https://{host}/eng/query/validate` to get `VALID` response

### Event Flow

```
PayFast ITN POST
  -> VerifyWebhookSignature middleware (IP + signature + server confirm)
  -> WebhookController
  -> match payment_status
  -> update Customer/Subscription/Transaction models
  -> fire events
```

### Events

| Event | Trigger |
|---|---|
| `PaymentComplete` | ITN with `payment_status = COMPLETE` |
| `PaymentFailed` | ITN with `payment_status = FAILED` |
| `SubscriptionCreated` | First successful payment on a recurring checkout |
| `SubscriptionUpdated` | Subscription details change |
| `SubscriptionCancelled` | ITN with `payment_status = CANCELLED` or API cancel |
| `SubscriptionPaused` | Subscription paused via API |

---

## 5. Models & Database

### `Customer` Model

| Column | Description |
|---|---|
| `provider_id` | PayFast token for the customer (from subscription tokenization) |
| `name` | Customer name |
| `email` | Customer email |
| `trial_ends_at` | Trial period end date |

### `Subscription` Model

| Column | Description |
|---|---|
| `provider_id` | PayFast subscription token |
| `status` | `active`, `paused`, `cancelled`, `past_due` |
| `frequency` | PayFast frequency: 3=monthly, 4=quarterly, 5=biannually, 6=annually |
| `cycles` | Number of billing cycles (0 = indefinite) |
| `trial_ends_at` | Trial end date |
| `ends_at` | Subscription end date |

### `SubscriptionItem` Model

Kept for compatibility but simplified. PayFast subscriptions are single-item (no multi-line subscriptions).

### `Transaction` Model

| Column | Description |
|---|---|
| `provider_id` | PayFast's `pf_payment_id` |
| `provider_subscription_id` | Links to subscription token |
| `payment_status` | PayFast status string |
| `amount_gross` | Gross amount from ITN |
| `amount_fee` | PayFast fee from ITN |
| `amount_net` | Net amount from ITN |

### Migration Changes

- Rename `paddle_id` to `provider_id` in customers, subscriptions, transactions tables
- Rename `paddle_subscription_id` to `provider_subscription_id` in transactions
- Add `frequency` (integer, nullable) and `cycles` (integer, default 0) to subscriptions
- Add `amount_gross`, `amount_fee`, `amount_net` (decimal columns) to transactions
- Remove Paddle-specific columns that have no PayFast mapping

---

## 6. Removals & Significant Changes

### Removed (no PayFast equivalent)

| Class | Reason |
|---|---|
| `Price` | Paddle has a prices API; PayFast does not. Pricing is defined in the app. |
| `PricePreview` | Same as above. |
| `PerformsCharges` concern | Paddle's one-off charge API has no PayFast equivalent. One-off payments use the same redirect checkout. |
| `CashierFake` | Needs full rewrite for PayFast flow. Deferred to later phase. |

### Significantly Changed

| Class | Change |
|---|---|
| `Checkout` | From JS overlay config to redirect form builder |
| `Payment` | Simplified to represent a PayFast payment with `pf_payment_id`, amounts, status |
| `Cashier` | `api()` method replaced with `PayFastClient` usage. Static helpers remain. |
| `ManagesTransactions` | No API to list past transactions. Transactions recorded locally from ITN only. |

### Kept (with adaptations)

| Class | Notes |
|---|---|
| `Billable` trait | Renamed methods (`providerName()`, `providerEmail()`) |
| `ManagesCustomer` | Adapted for PayFast customer tokens |
| `ManagesSubscriptions` | Adapted for PayFast subscription tokens and API |
| `Prorates` | PayFast doesn't support prorating natively; kept for app-level logic |
| `CashierServiceProvider` | Namespace + directive/component renames |
| Event classes | Renamed to match PayFast terminology |

---

## 7. Testing Strategy

### Unit Tests

- `PayFastClient` — mock HTTP responses, verify signature generation, validate ITN processing logic
- `Checkout` — verify correct form field generation, signature calculation, URL building
- `SubscriptionBuilder` — verify it produces correct checkout data for recurring payments

### Integration Tests

- `WebhookController` — send simulated ITN payloads, verify models update correctly, events fire
- `VerifyWebhookSignature` — test IP validation, signature checks, server confirmation (mocked)
- Billable trait — test `providerName()`, `providerEmail()`, subscription creation flow

### Feature Tests

- Full checkout -> ITN -> subscription active flow using sandbox credentials
- Subscription lifecycle: create -> pause -> unpause -> cancel

### Test Infrastructure

- Existing test fixtures/factories adapted for PayFast field names
- `CashierFake` deferred — tests use mocked `PayFastClient` instead for now
