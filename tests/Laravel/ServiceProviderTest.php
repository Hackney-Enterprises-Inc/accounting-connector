<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Hei\AccountingConnector\ConnectorManager;
use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Contracts\EntityMap;
use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Events\EntityCreated;
use Hei\AccountingConnector\Exceptions\AccountingConnectorException;
use Hei\AccountingConnector\Http\HttpClient;
use Hei\AccountingConnector\Http\HttpResponse;
use Hei\AccountingConnector\Http\NullRequestGate;
use Hei\AccountingConnector\Http\RequestGate;
use Hei\AccountingConnector\Laravel\AccountingConnectorServiceProvider;
use Hei\AccountingConnector\Laravel\DatabaseConnectionRepository;
use Hei\AccountingConnector\Laravel\DatabaseEntityMap;
use Hei\AccountingConnector\Laravel\DatabaseLookupStore;
use Hei\AccountingConnector\Laravel\LaravelEventDispatcher;
use Hei\AccountingConnector\Support\NullConnectionStore;
use Hei\AccountingConnector\Testing\FakeConnector;
use Illuminate\Support\Facades\Event;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;

it('registers a connector for a configured provider', function () {
    $manager = app(ConnectorManager::class);

    expect($manager->has(Provider::Xero))->toBeTrue()
        ->and($manager->for(Provider::Xero))->toBeInstanceOf(XeroConnector::class);
});

it('leaves an unconfigured provider unregistered so the failure is immediate and clear', function () {
    // Better to fail here with a message naming the missing config than to 401
    // against Intuit halfway through a queue job.
    $manager = app(ConnectorManager::class);

    expect($manager->has(Provider::QuickBooksOnline))->toBeFalse()
        ->and(fn () => $manager->for(Provider::QuickBooksOnline))
        ->toThrow(AccountingConnectorException::class, 'No connector is registered for QuickBooks Online');
});

it('resolves the connector a connection belongs to', function () {
    $connection = new Connection(Provider::Xero, 'tenant-1', 'token');

    expect(app(ConnectorManager::class)->forConnection($connection))
        ->toBeInstanceOf(XeroConnector::class);
});

it('binds a database entity map by default', function () {
    expect(app(EntityMap::class))->toBeInstanceOf(DatabaseEntityMap::class);
});

it('ships a working connection store rather than making the host write one', function () {
    // This used to default to a null store that warned every time it discarded a
    // refresh. Providing a real one removes the single most consequential piece of
    // glue a host could get wrong: Intuit rotates its refresh token on every
    // refresh, and a missed write kills the connection days later.
    expect(app(ConnectionStore::class))->toBeInstanceOf(DatabaseConnectionRepository::class);
});

it('falls back to the loud null store when a host keeps connections elsewhere', function () {
    config()->set('accounting-connector.connections.enabled', false);
    app()->forgetInstance(ConnectionStore::class);

    expect(app(ConnectionStore::class))->toBeInstanceOf(NullConnectionStore::class);
});

it('binds a database lookup store by default', function () {
    expect(app(LookupStore::class))->toBeInstanceOf(DatabaseLookupStore::class);
});

it('hands Laravel cache repository straight to the framework-free core', function () {
    // Laravel's repository extends PSR-16 CacheInterface, so no adapter is needed.
    expect(app('cache')->store())->toBeInstanceOf(CacheInterface::class);
});

it('does not claim the app-wide PSR-14 binding', function () {
    // Owning Psr\EventDispatcher\EventDispatcherInterface app-wide would collide
    // with any other package that binds it, and losing that race silently reroutes
    // sync events away from Laravel's listeners.
    expect(app()->bound(EventDispatcherInterface::class))->toBeFalse();
});

it('bridges package events onto Laravel listeners', function () {
    Event::fake([EntityCreated::class]);

    $connection = new Connection(Provider::Xero, 'tenant-1', 'token', reference: 'org-1');

    app(LaravelEventDispatcher::class, ['events' => app('events')])
        ->dispatch(new EntityCreated($connection, EntityType::Bill, 'invoice-1'));

    Event::assertDispatched(EntityCreated::class);
});

it('lets a fake be swapped in for the whole sync path', function () {
    $fake = new FakeConnector(Provider::Xero);

    app(ConnectorManager::class)->set(Provider::Xero, $fake);

    expect(app(ConnectorManager::class)->for(Provider::Xero))->toBe($fake);
});

it('publishes all three migrations rather than running any of its own', function () {
    // Each application owns its own schema and its own migration ordering.
    $pattern = database_path('migrations/*_create_accounting_*_table.php');
    $sweep = function () use ($pattern): void {
        foreach (glob($pattern) ?: [] as $file) {
            unlink($file);
        }
    };

    // Sweep first as well as last. Publishing writes into the testbench skeleton,
    // which persists between runs, so a test that only cleans up on success leaves
    // debris that makes the next run fail for the wrong reason.
    $sweep();

    try {
        $this->artisan('vendor:publish', ['--tag' => 'accounting-connector-migrations'])->assertSuccessful();

        expect(glob($pattern) ?: [])->toHaveCount(3);
    } finally {
        $sweep();
    }
});

it('republishes migrations over the existing files rather than duplicating them', function () {
    // A second timestamped copy of the same migration fails `migrate` on a table
    // that already exists, which is how a re-publish breaks the next deploy. The
    // duplicate only happens across boots (each boot stamps a fresh timestamp), so
    // this plants a file from an "earlier deploy" and boots the provider again.
    $pattern = database_path('migrations/*_create_accounting_*_table.php');
    $sweep = function () use ($pattern): void {
        foreach (glob($pattern) ?: [] as $file) {
            unlink($file);
        }
    };

    $sweep();

    try {
        $existing = database_path('migrations/2020_01_01_000000_create_accounting_connections_table.php');
        file_put_contents($existing, '<?php // published by an earlier deploy');

        (new AccountingConnectorServiceProvider(app()))->boot();

        $this->artisan('vendor:publish', ['--tag' => 'accounting-connector-migrations', '--force' => true])->assertSuccessful();

        $published = glob($pattern) ?: [];
        $connections = array_values(array_filter(
            $published,
            fn (string $file): bool => str_contains($file, 'create_accounting_connections_table'),
        ));

        expect($published)->toHaveCount(3)
            ->and($connections)->toBe([$existing])
            ->and((string) file_get_contents($existing))->toContain('Schema::create');
    } finally {
        $sweep();
    }
});

it('leaves the request gate open unless the host binds one', function () {
    expect(app(RequestGate::class))->toBeInstanceOf(NullRequestGate::class);
});

it('hands a host-bound request gate to the HTTP client', function () {
    $gate = new class implements RequestGate
    {
        public int $asked = 0;

        public function acquire(?Provider $provider, ?string $tenantId): void
        {
            $this->asked++;

            throw new AccountingConnectorException('stopped by the gate');
        }

        public function observe(HttpResponse $response, ?Provider $provider, ?string $tenantId): void {}

        public function release(?Provider $provider, ?string $tenantId, Throwable $failure): void {}
    };

    $this->app->instance(RequestGate::class, $gate);
    $this->app->forgetInstance(HttpClient::class);

    expect(fn () => app(HttpClient::class)->send('GET', 'https://api.xero.com/never'))
        ->toThrow(AccountingConnectorException::class, 'stopped by the gate');

    expect($gate->asked)->toBe(1);
});

it('builds the HTTP client over a Guzzle client with timeouts rather than a discovered one', function () {
    config()->set('accounting-connector.http.timeout', 12);
    config()->set('accounting-connector.http.connect_timeout', 3);
    $this->app->forgetInstance(HttpClient::class);

    $client = app(HttpClient::class);

    $property = new ReflectionProperty(HttpClient::class, 'client');
    $guzzle = $property->getValue($client);

    expect($guzzle)->toBeInstanceOf(Client::class);

    /** @var Client $guzzle */
    $config = (new ReflectionProperty(Client::class, 'config'))->getValue($guzzle);

    expect($config)->toBeArray()
        ->and($config['timeout'])->toBe(12.0)
        ->and($config['connect_timeout'])->toBe(3.0);
});

it('gives the Xero connector the configured bank transaction page size', function () {
    config()->set('accounting-connector.bank_transactions.page_size', 500);
    $this->app->forgetInstance(ConnectorManager::class);

    $connector = app(ConnectorManager::class)->for(Provider::Xero);

    $size = (new ReflectionProperty(XeroConnector::class, 'bankTransactionPageSize'))->getValue($connector);

    expect($size)->toBe(500);
});
