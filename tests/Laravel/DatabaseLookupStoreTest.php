<?php

declare(strict_types=1);

use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Laravel\DatabaseLookupStore;
use Illuminate\Support\Facades\DB;

function conn(string $owner = 'org-1', Provider $provider = Provider::Xero): Connection
{
    return new Connection(
        provider: $provider,
        tenantId: 'tenant-abc',
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
