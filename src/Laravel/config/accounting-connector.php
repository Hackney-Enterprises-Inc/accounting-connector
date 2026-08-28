<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth client credentials
    |--------------------------------------------------------------------------
    |
    | Each application registers its OWN app with Xero and with Intuit. That is
    | the entire reason this is a package and not a shared service: Intuit reviews
    | per app, and the consent screen the customer reads names the app requesting
    | access. Sharing one Intuit app across two products would show one product's
    | customers the other product's name.
    |
    | A provider with no client id is simply not registered, and asking the manager
    | for it throws rather than failing later against the API.
    |
    */

    'xero' => [
        'client_id' => env('XERO_CLIENT_ID'),
        'client_secret' => env('XERO_CLIENT_SECRET'),
        'redirect_uri' => env('XERO_REDIRECT_URI'),
    ],

    'quickbooks' => [
        'client_id' => env('QUICKBOOKS_CLIENT_ID'),
        'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
        'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI'),

        /*
         * Point at https://sandbox-quickbooks.api.intuit.com to work against an
         * Intuit sandbox company. The OAuth endpoints are the same either way, so
         * this is the only switch.
         */
        'base_url' => env('QUICKBOOKS_BASE_URL', 'https://quickbooks.api.intuit.com'),

        /*
         * Intuit versions its API by query parameter rather than by path. Fields
         * appear and change meaning between versions, so pin this deliberately and
         * re-test when you move it.
         */
        'minor_version' => env('QUICKBOOKS_MINOR_VERSION', '75'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    |
    | Retries apply to 429s, 5xx responses and transport failures only. A 4xx is
    | never retried: the same rejected payload will be rejected the same way.
    |
    | Xero allows 5 requests in flight per tenant and 60 calls a minute, shared
    | with every other app the customer has connected. Retries here do not protect
    | you from a queue running 20 sync workers at once; keep that concurrency low.
    |
    */

    'http' => [
        'max_retries' => env('ACCOUNTING_CONNECTOR_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lookup cache
    |--------------------------------------------------------------------------
    |
    | Chart of accounts, bank accounts and tax codes are cached per connection.
    | These change rarely and are read on every settings page load, so caching them
    | is the difference between a snappy form and a rate-limited one.
    |
    | Set 'store' to null to use the application's default cache store.
    |
    */

    'cache' => [
        'enabled' => env('ACCOUNTING_CONNECTOR_CACHE', true),
        'store' => env('ACCOUNTING_CONNECTOR_CACHE_STORE'),
        'ttl' => env('ACCOUNTING_CONNECTOR_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | One row per tenant per provider, in one table. This is what stops each new
    | provider costing another four columns on your tenant model.
    |
    | Tokens are encrypted with the application key before they are written.
    |
    | The `provider` column is NOT constrained to this package's providers. Keep
    | connections for integrations the package has no connector for, such as Google
    | Sheets, in the same table and read them with your own service.
    |
    | Set 'enabled' to false to keep connections wherever you already do; the
    | connectors then fall back to a NullConnectionStore that warns loudly every time
    | it discards a refresh, and you must bind your own ConnectionStore.
    |
    */

    'connections' => [
        'enabled' => env('ACCOUNTING_CONNECTOR_CONNECTIONS', true),
        'connection' => env('ACCOUNTING_CONNECTOR_DB_CONNECTION'),
        'table' => 'accounting_connections',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lookup storage
    |--------------------------------------------------------------------------
    |
    | Durable storage for chart-of-accounts, tax-code and tracking-category lists,
    | sitting behind the cache configured above. Two things it buys you: the lists
    | survive a cache flush, so a deploy does not send every tenant back to the
    | provider at once against a shared per-minute rate ceiling; and a stored list
    | is served when the provider is unreachable, so an outage leaves a settings
    | page stale rather than empty.
    |
    | One row per lookup key, so concurrent refreshes cannot clobber each other.
    |
    */

    'lookups' => [
        'enabled' => env('ACCOUNTING_CONNECTOR_LOOKUP_STORE', true),
        'connection' => env('ACCOUNTING_CONNECTOR_DB_CONNECTION'),
        'table' => 'accounting_connection_lookups',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity map
    |--------------------------------------------------------------------------
    |
    | Where local-id to external-id correspondences live. The migration is
    | publishable rather than automatic, because each application owns its own
    | schema:
    |
    |     php artisan vendor:publish --tag=accounting-connector-migrations
    |
    | Leave 'enabled' false to supply your own EntityMap implementation instead,
    | by binding Hei\AccountingConnector\Contracts\EntityMap in a provider.
    |
    */

    'entity_map' => [
        'enabled' => env('ACCOUNTING_CONNECTOR_ENTITY_MAP', true),
        'connection' => env('ACCOUNTING_CONNECTOR_DB_CONNECTION'),
        'table' => 'accounting_entity_map',
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Sync events are dispatched through Laravel's event dispatcher, so they can be
    | listened for exactly like any other event. Turn this off to silence them.
    |
    */

    'events' => [
        'enabled' => env('ACCOUNTING_CONNECTOR_EVENTS', true),
    ],

];
