<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\AccountingConnector;
use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Laravel\DatabaseLookupStore;
use Illuminate\Support\Facades\DB;

function conn(string $owner = 'org-1', Provider $provider = Provider::Xero, string $tenant = 'tenant-abc'): Connection
{
    return new Connection(
        provider: $provider,
        tenantId: $tenant,
        accessToken: 'token',
        reference: $owner,
    );
}

it('stores and reads back a lookup list', function () {
    $store = app(LookupStore::class);
    $store->put(conn(), 'chart_of_accounts', [
        ['id' => '1', 'name' => 'General Expenses', 'class' => 'expense'],
    ]);

    expect($store->get(conn(), 'chart_of_accounts'))->toBe([
        ['id' => '1', 'name' => 'General Expenses', 'class' => 'expense'],
    ]);
});

it('keeps each lookup key in its own row so concurrent refreshes cannot clobber', function () {
    // The reason this is a table rather than a JSON column on the connection. A
    // single blob would make every refresh a read-modify-write.
    $store = app(LookupStore::class);
    $store->put(conn(), 'chart_of_accounts', [['id' => '1', 'name' => 'Expenses']]);
    $store->put(conn(), 'tax_codes', [['reference' => 'NONE', 'name' => 'Tax Exempt']]);
    $store->put(conn(), 'tracking_categories', [['id' => 'cat-1', 'name' => 'Region']]);

    expect(DB::table('accounting_connection_lookups')->count())->toBe(3)
        ->and($store->get(conn(), 'chart_of_accounts'))->toHaveCount(1)
        ->and($store->get(conn(), 'tax_codes')[0]['name'])->toBe('Tax Exempt');
});

it('replaces a list rather than accumulating rows', function () {
    $store = app(LookupStore::class);
    $store->put(conn(), 'chart_of_accounts', [['id' => '1', 'name' => 'Old']]);
    $store->put(conn(), 'chart_of_accounts', [['id' => '2', 'name' => 'New']]);

    expect(DB::table('accounting_connection_lookups')->count())->toBe(1)
        ->and($store->get(conn(), 'chart_of_accounts')[0]['name'])->toBe('New');
});

it('keeps owners and providers apart', function () {
    $store = app(LookupStore::class);
    $store->put(conn('org-1'), 'chart_of_accounts', [['id' => '1', 'name' => 'Org one account']]);

    expect($store->get(conn('org-2'), 'chart_of_accounts'))->toBeNull()
        ->and($store->get(conn('org-1', Provider::QuickBooksOnline), 'chart_of_accounts'))->toBeNull()
        ->and($store->get(conn('org-1'), 'chart_of_accounts'))->toHaveCount(1);
});

it('returns null for a key it has never stored', function () {
    expect(app(LookupStore::class)->get(conn(), 'nothing_here'))->toBeNull();
});

it('records when a list was last synced, replacing the old synced_at column', function () {
    $store = app(DatabaseLookupStore::class);
    $store->put(conn(), 'chart_of_accounts', [['id' => '1', 'name' => 'Expenses']]);

    expect($store->syncedAt(conn(), 'chart_of_accounts'))->not->toBeNull()
        ->and($store->syncedAt(conn(), 'tax_codes'))->toBeNull();
});

it('flushes every cached list for a connection on disconnect', function () {
    $store = app(DatabaseLookupStore::class);
    $store->put(conn('org-1'), 'chart_of_accounts', [['id' => '1']]);
    $store->put(conn('org-1'), 'tax_codes', [['reference' => 'NONE']]);
    $store->put(conn('org-2'), 'chart_of_accounts', [['id' => '9']]);

    $store->flush(conn('org-1'));

    expect(DB::table('accounting_connection_lookups')->count())->toBe(1)
        ->and($store->get(conn('org-2'), 'chart_of_accounts'))->toHaveCount(1);
});

it('does nothing rather than failing when a connection carries no owner', function () {
    // A connection built ad hoc for a one-off script has no reference. The connector
    // still works, it just re-fetches every time.
    $store = app(LookupStore::class);
    $anonymous = new Connection(Provider::Xero, 'tenant-abc', 'token');

    $store->put($anonymous, 'chart_of_accounts', [['id' => '1']]);

    expect(DB::table('accounting_connection_lookups')->count())->toBe(0)
        ->and($store->get($anonymous, 'chart_of_accounts'))->toBeNull();
});

it('never serves one tenant the list stored for another under the same owner', function () {
    // An organization disconnects Xero company A and connects company B. The owner
    // and provider are unchanged, so without the tenant in the key B would be shown
    // A's chart of accounts until something forced a refresh.
    $store = app(DatabaseLookupStore::class);
    $store->put(conn(tenant: 'tenant-a'), 'chart_of_accounts', [['id' => 'a-1', 'name' => 'Company A account']]);

    expect($store->get(conn(tenant: 'tenant-b'), 'chart_of_accounts'))->toBeNull()
        ->and($store->syncedAt(conn(tenant: 'tenant-b'), 'chart_of_accounts'))->toBeNull()
        ->and($store->get(conn(tenant: 'tenant-a'), 'chart_of_accounts')[0]['id'])->toBe('a-1');
});

it('keeps each tenant of an owner in its own row, and flushes them all on disconnect', function () {
    $store = app(DatabaseLookupStore::class);
    $store->put(conn(tenant: 'tenant-a'), 'chart_of_accounts', [['id' => 'a-1']]);
    $store->put(conn(tenant: 'tenant-b'), 'chart_of_accounts', [['id' => 'b-1']]);

    expect(DB::table('accounting_connection_lookups')->count())->toBe(2)
        ->and($store->get(conn(tenant: 'tenant-a'), 'chart_of_accounts')[0]['id'])->toBe('a-1')
        ->and($store->get(conn(tenant: 'tenant-b'), 'chart_of_accounts')[0]['id'])->toBe('b-1');

    $store->flush(conn(tenant: 'tenant-b'));

    expect(DB::table('accounting_connection_lookups')->count())->toBe(0);
});

it('stores the tenant as a hashed suffix on the lookup key, never the raw id', function () {
    app(LookupStore::class)->put(conn(tenant: 'tenant-a'), 'tax_codes', [['reference' => 'NONE']]);

    $key = DB::table('accounting_connection_lookups')->value('lookup_key');

    expect($key)->toBe('tax_codes@'.substr(hash('sha256', 'tenant-a'), 0, 16))
        ->and($key)->not->toContain('tenant-a');
});

it('treats a row written before tenant scoping as a miss, then stores alongside it', function () {
    // What a host upgrading from 0.2.0 holds: a bare lookup key with no tenant. It
    // cannot say which company it came from, so it is never served to anyone.
    $now = date('Y-m-d H:i:s');
    DB::table('accounting_connection_lookups')->insert([
        'owner_id' => 'org-1',
        'provider' => 'xero',
        'lookup_key' => 'chart_of_accounts',
        'payload' => json_encode([['id' => 'legacy', 'name' => 'Some other company']]),
        'synced_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $store = app(DatabaseLookupStore::class);

    expect($store->get(conn(), 'chart_of_accounts'))->toBeNull()
        ->and($store->syncedAt(conn(), 'chart_of_accounts'))->toBeNull();

    $store->put(conn(), 'chart_of_accounts', [['id' => 'fresh']]);

    expect($store->get(conn(), 'chart_of_accounts')[0]['id'])->toBe('fresh');
});

it('refetches from the provider after a tenant switch, even with the store warm', function () {
    // End to end through the connector: company A's chart is stored, the owner
    // reconnects to company B, and the first lookup for B goes to Xero.
    $store = app(DatabaseLookupStore::class);
    $store->put(conn(tenant: 'tenant-a'), AccountingConnector::LOOKUP_CHART_OF_ACCOUNTS, [
        ['id' => 'a-only', 'name' => 'Company A account', 'class' => 'expense'],
    ]);

    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/accounts'));
    $connector = new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        lookups: $store,
    );
    $tenantB = new Connection(
        Provider::Xero,
        'tenant-b',
        'token',
        expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
        reference: 'org-1',
    );

    $accounts = $connector->chartOfAccounts($tenantB);

    expect($fake->requests)->toHaveCount(1)
        ->and(array_map(fn (Account $a): string => $a->id, $accounts))->not->toContain('a-only')
        ->and(array_column($store->get($tenantB, AccountingConnector::LOOKUP_CHART_OF_ACCOUNTS) ?? [], 'description', 'id'))
        ->toMatchArray(['exp-uuid' => 'General expenses']);
});
