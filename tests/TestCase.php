<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Tests;

use Hei\AccountingConnector\Laravel\AccountingConnectorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AccountingConnectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The connection repository encrypts tokens with the application key, so a
        // host without one cannot store credentials at all. That is the correct
        // failure mode, but the test app has to supply one.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('accounting-connector.xero.client_id', 'xero-client');
        $app['config']->set('accounting-connector.xero.client_secret', 'xero-secret');
        $app['config']->set('accounting-connector.xero.redirect_uri', 'https://app.test/xero/callback');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database');
    }
}
