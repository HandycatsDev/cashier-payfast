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
