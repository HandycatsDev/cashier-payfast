<?php

namespace Tests;

use HandycatsDev\CashierPayFast\CashierServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [CashierServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('auth.providers.users.model', \Workbench\App\Models\User::class);
        $app['config']->set('cashier.merchant_id', '10000100');
        $app['config']->set('cashier.merchant_key', '46f0cd694581a');
        $app['config']->set('cashier.passphrase', '');
        $app['config']->set('cashier.sandbox', true);
    }
}
