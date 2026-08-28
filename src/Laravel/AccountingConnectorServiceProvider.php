<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Laravel;

use Hei\AccountingConnector\ConnectorManager;
use Hei\AccountingConnector\Connectors\QuickBooks\QuickBooksConnector;
use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\ConnectionRepository;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Contracts\EntityMap;
use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Http\HttpClient;
use Hei\AccountingConnector\Support\NullConnectionStore;
use Hei\AccountingConnector\Support\NullEntityMap;
use Hei\AccountingConnector\Support\NullLookupStore;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Wires the package into a Laravel application.
 *
 * What it binds for you: the manager, an HTTP client, the PSR-14 event bridge, and
 * a database-backed entity map when one is configured.
 *
 * What it deliberately leaves to you: the ConnectionStore. There is no way for the
 * package to guess where a host keeps its tokens, and getting it wrong silently
 * breaks QuickBooks a few days later, so the default is a NullConnectionStore that
 * warns every time it discards a refresh. Bind your own:
 *
 *     $this->app->bind(ConnectionStore::class, OrganizationConnectionStore::class);
 */
final class AccountingConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/accounting-connector.php', 'accounting-connector');

        $this->app->singleton(HttpClient::class, function (Container $app): HttpClient {
            return HttpClient::discover(
                logger: $app->make(LoggerInterface::class),
                maxRetries: (int) config('accounting-connector.http.max_retries', 3),
            );
        });

        // Bound as the concrete class, deliberately not as the PSR-14 interface.
        // Claiming the app-wide Psr\EventDispatcher\EventDispatcherInterface slot
        // would collide with any other package that binds it, and losing that race
        // silently reroutes sync events away from Laravel's listeners.
        $this->app->singleton(LaravelEventDispatcher::class, function (Container $app): LaravelEventDispatcher {
            return new LaravelEventDispatcher($app->make(LaravelDispatcher::class));
        });

        // Connections live in one table rather than as per-provider columns on the
        // host's tenant model, so a third provider costs a row instead of four more
        // columns. Binding the same object to both contracts means rotated
        // QuickBooks refresh tokens persist with no glue from the host, which is the
        // single most consequential thing to get wrong in this package.
        $this->app->singleton(DatabaseConnectionRepository::class, function (Container $app): DatabaseConnectionRepository {
            return new DatabaseConnectionRepository(
                resolver: $app->make(ConnectionResolverInterface::class),
                encrypter: $app->make(Encrypter::class),
                table: (string) config('accounting-connector.connections.table', 'accounting_connections'),
                connectionName: config('accounting-connector.connections.connection'),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->bind(ConnectionRepository::class, DatabaseConnectionRepository::class);

        $this->app->bind(ConnectionStore::class, function (Container $app): ConnectionStore {
            if (! config('accounting-connector.connections.enabled', true)) {
                return new NullConnectionStore($app->make(LoggerInterface::class));
            }

            return $app->make(DatabaseConnectionRepository::class);
        });

        // Durable storage for lookup lists, behind the cache. Without it a cache
        // flush costs every organization a fresh round of provider calls, and an
        // outage empties their settings dropdowns.
        $this->app->singleton(LookupStore::class, function (Container $app): LookupStore {
            if (! config('accounting-connector.lookups.enabled', true)) {
                return new NullLookupStore;
            }

            return new DatabaseLookupStore(
                resolver: $app->make(ConnectionResolverInterface::class),
                table: (string) config('accounting-connector.lookups.table', 'accounting_connection_lookups'),
                connectionName: config('accounting-connector.lookups.connection'),
            );
        });

        $this->app->singleton(EntityMap::class, function (Container $app): EntityMap {
            if (! config('accounting-connector.entity_map.enabled', true)) {
                return new NullEntityMap;
            }

            return new DatabaseEntityMap(
                resolver: $app->make(ConnectionResolverInterface::class),
                table: (string) config('accounting-connector.entity_map.table', 'accounting_entity_map'),
                connectionName: config('accounting-connector.entity_map.connection'),
            );
        });

        $this->app->singleton(ConnectorManager::class, function (Container $app): ConnectorManager {
            $manager = new ConnectorManager;

            if ($this->configured('xero')) {
                $manager->register(Provider::Xero, fn (): XeroConnector => new XeroConnector(
                    http: $app->make(HttpClient::class),
                    clientId: (string) config('accounting-connector.xero.client_id'),
                    clientSecret: (string) config('accounting-connector.xero.client_secret'),
                    redirectUri: (string) config('accounting-connector.xero.redirect_uri'),
                    connections: $app->make(ConnectionStore::class),
                    entityMap: $app->make(EntityMap::class),
                    lookups: $app->make(LookupStore::class),
                    cache: $this->cache($app),
                    events: $this->events($app),
                    logger: $app->make(LoggerInterface::class),
                    lookupTtl: (int) config('accounting-connector.cache.ttl', 3600),
                ));
            }

            if ($this->configured('quickbooks')) {
                $manager->register(Provider::QuickBooksOnline, function () use ($app): QuickBooksConnector {
                    $connector = new QuickBooksConnector(
                        http: $app->make(HttpClient::class),
                        clientId: (string) config('accounting-connector.quickbooks.client_id'),
                        clientSecret: (string) config('accounting-connector.quickbooks.client_secret'),
                        redirectUri: (string) config('accounting-connector.quickbooks.redirect_uri'),
                        connections: $app->make(ConnectionStore::class),
                        entityMap: $app->make(EntityMap::class),
                        lookups: $app->make(LookupStore::class),
                        cache: $this->cache($app),
                        events: $this->events($app),
                        logger: $app->make(LoggerInterface::class),
                        lookupTtl: (int) config('accounting-connector.cache.ttl', 3600),
                    );

                    return $connector
                        ->usingBaseUrl((string) config('accounting-connector.quickbooks.base_url'))
                        ->usingMinorVersion((string) config('accounting-connector.quickbooks.minor_version'));
                });
            }

            return $manager;
        });

        $this->app->alias(ConnectorManager::class, 'accounting-connectors');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/config/accounting-connector.php' => config_path('accounting-connector.php'),
        ], 'accounting-connector-config');

        // Published in dependency order, one second apart, so the timestamps sort the
        // way the tables read even though nothing here declares a foreign key.
        $this->publishes([
            __DIR__.'/database/migrations/create_accounting_connections_table.php.stub' => $this->migrationPath('create_accounting_connections_table', 0),
            __DIR__.'/database/migrations/create_accounting_connection_lookups_table.php.stub' => $this->migrationPath('create_accounting_connection_lookups_table', 1),
            __DIR__.'/database/migrations/create_accounting_entity_map_table.php.stub' => $this->migrationPath('create_accounting_entity_map_table', 2),
        ], 'accounting-connector-migrations');
    }

    /**
     * A provider with no client id is left unregistered, so asking for it fails
     * immediately with a clear message rather than 401ing against the vendor.
     */
    private function configured(string $provider): bool
    {
        return ! empty(config("accounting-connector.{$provider}.client_id"));
    }

    private function cache(Container $app): ?CacheInterface
    {
        if (! config('accounting-connector.cache.enabled', true)) {
            return null;
        }

        $store = config('accounting-connector.cache.store');

        // Laravel's cache repository extends PSR-16 CacheInterface, so it can be
        // handed to the framework-free core without an adapter.
        $repository = $app->make('cache')->store(is_string($store) ? $store : null);

        return $repository instanceof CacheInterface ? $repository : null;
    }

    private function events(Container $app): ?EventDispatcherInterface
    {
        return config('accounting-connector.events.enabled', true)
            ? $app->make(LaravelEventDispatcher::class)
            : null;
    }

    /**
     * Timestamped so a published migration sorts after whatever is already there,
     * with an offset so the three published migrations keep their relative order.
     *
     * A migration already published keeps its existing filename, so re-running
     * vendor:publish overwrites it in place rather than leaving two copies whose
     * second `migrate` fails on a table that already exists.
     */
    private function migrationPath(string $name, int $offsetSeconds = 0): string
    {
        $existing = glob(database_path('migrations/*_'.$name.'.php'));

        if (is_array($existing) && $existing !== []) {
            return $existing[0];
        }

        return database_path(
            'migrations/'.date('Y_m_d_His', time() + $offsetSeconds).'_'.$name.'.php'
        );
    }
}
