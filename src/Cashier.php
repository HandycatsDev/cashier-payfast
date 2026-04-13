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
     * Custom callback for resolving a billable from the ITN payload.
     *
     * Receives the full ITN payload array and must return a billable model
     * (e.g. an instance of the model using the Billable trait) or null.
     * Used by the webhook handler as a fallback when the billable cannot be
     * resolved via an existing subscription token, and before the email-based
     * lookup. Useful for multi-tenant or custom flows where the billable
     * identity is encoded in `custom_str1` (e.g. a `tenant_id`).
     *
     * @var (callable(array): mixed)|null
     */
    public static $resolveBillableUsing = null;

    public static function findBillable($providerId)
    {
        return (new static::$customerModel)->where('provider_id', $providerId)->first()?->billable;
    }

    /**
     * Register a callback to resolve a billable from the ITN payload.
     *
     * @param  callable(array): mixed  $callback
     */
    public static function resolveBillableUsing(callable $callback): void
    {
        static::$resolveBillableUsing = $callback;
    }

    public static function webhookUrl(): string
    {
        return config('cashier.notify_url') ?? route('cashier.webhook');
    }

    public static function formatCurrencyUsing(callable $callback): void
    {
        static::$formatCurrencyUsing = $callback;
    }

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
