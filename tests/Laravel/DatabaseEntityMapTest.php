<?php

declare(strict_types=1);

use Hei\AccountingConnector\Contracts\EntityMap;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Illuminate\Support\Facades\DB;

function xeroConnection(string $tenant = 'tenant-a', ?string $owner = 'org-1'): Connection
{
    return new Connection(Provider::Xero, $tenant, 'token', reference: $owner);
}

it('persists a correspondence to the table', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection(), EntityType::Bill, 'doc-42', 'invoice-1');

    expect($map->externalId(xeroConnection(), EntityType::Bill, 'doc-42'))->toBe('invoice-1')
        ->and(DB::table('accounting_entity_map')->count())->toBe(1);
});

it('resolves the reverse direction for an inbound webhook', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection(), EntityType::Bill, 'doc-42', 'invoice-1');

    expect($map->localId(xeroConnection(), EntityType::Bill, 'invoice-1'))->toBe('doc-42');
});

it('scopes to the tenant, not just the provider', function () {
    // Two organizations will both hold a local id of '1'. Crossing them posts a
    // customer's money into a stranger's ledger.
    $map = app(EntityMap::class);
    $map->remember(xeroConnection('tenant-a'), EntityType::Bill, '1', 'invoice-for-a');
    $map->remember(xeroConnection('tenant-b'), EntityType::Bill, '1', 'invoice-for-b');

    expect($map->externalId(xeroConnection('tenant-a'), EntityType::Bill, '1'))->toBe('invoice-for-a')
        ->and($map->externalId(xeroConnection('tenant-b'), EntityType::Bill, '1'))->toBe('invoice-for-b')
        ->and(DB::table('accounting_entity_map')->count())->toBe(2);
});

it('upserts rather than colliding when the same document is remembered twice', function () {
    // A re-sync must replace the mapping, not hit the unique index and blow up a
    // queue job with a database error.
    $map = app(EntityMap::class);
    $map->remember(xeroConnection(), EntityType::Bill, 'doc-42', 'invoice-old');
    $map->remember(xeroConnection(), EntityType::Bill, 'doc-42', 'invoice-new');

    expect($map->externalId(xeroConnection(), EntityType::Bill, 'doc-42'))->toBe('invoice-new')
        ->and(DB::table('accounting_entity_map')->count())->toBe(1);
});

it('keeps entity types apart for the same local id', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection(), EntityType::Bill, 'doc-1', 'invoice-1');
    $map->remember(xeroConnection(), EntityType::Expense, 'doc-1', 'txn-1');

    expect($map->externalId(xeroConnection(), EntityType::Bill, 'doc-1'))->toBe('invoice-1')
        ->and($map->externalId(xeroConnection(), EntityType::Expense, 'doc-1'))->toBe('txn-1');
});

it('forgets a mapping so the next sync creates a fresh entity', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection(), EntityType::Bill, 'doc-1', 'invoice-1');
    $map->forget(xeroConnection(), EntityType::Bill, 'doc-1');

    expect($map->externalId(xeroConnection(), EntityType::Bill, 'doc-1'))->toBeNull()
        ->and(DB::table('accounting_entity_map')->count())->toBe(0);
});

it('forgets only the row it was asked about', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection('tenant-a'), EntityType::Bill, '1', 'invoice-for-a');
    $map->remember(xeroConnection('tenant-b'), EntityType::Bill, '1', 'invoice-for-b');

    $map->forget(xeroConnection('tenant-a'), EntityType::Bill, '1');

    expect($map->externalId(xeroConnection('tenant-b'), EntityType::Bill, '1'))->toBe('invoice-for-b');
});

it('returns null for anything it has never seen', function () {
    expect(app(EntityMap::class)->externalId(xeroConnection(), EntityType::Bill, 'nope'))->toBeNull()
        ->and(app(EntityMap::class)->localId(xeroConnection(), EntityType::Bill, 'nope'))->toBeNull();
});

it('stores a name-keyed contact, which is how most vendors arrive', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection(), EntityType::Vendor, 'name:acme supply', 'contact-1');

    expect($map->externalId(xeroConnection(), EntityType::Vendor, 'name:acme supply'))->toBe('contact-1');
});

it('records the owner that wrote the mapping', function () {
    // Write-only audit: the row can be traced back to the tenant it was posted
    // for, which the entity map itself never needs and a host reconciling a
    // mis-scoped sync very much does.
    $map = app(EntityMap::class);
    $map->remember(xeroConnection(), EntityType::Bill, 'doc-42', 'invoice-1');

    expect(DB::table('accounting_entity_map')->value('owner_id'))->toBe('org-1');
});

it('leaves the owner null when the connection names none', function () {
    // A connection with no reference stores null rather than an empty string, so
    // "nobody said" reads as unknown rather than as an owner called ''.
    $map = app(EntityMap::class);
    $map->remember(xeroConnection('tenant-a', null), EntityType::Bill, 'doc-1', 'invoice-1');
    $map->remember(xeroConnection('tenant-a', ''), EntityType::Bill, 'doc-2', 'invoice-2');

    expect(DB::table('accounting_entity_map')->whereNull('owner_id')->count())->toBe(2);
});

it('refreshes the owner when the mapping is written again', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection('tenant-a', 'org-1'), EntityType::Bill, 'doc-42', 'invoice-1');
    $map->remember(xeroConnection('tenant-a', 'org-2'), EntityType::Bill, 'doc-42', 'invoice-2');

    expect(DB::table('accounting_entity_map')->count())->toBe(1)
        ->and(DB::table('accounting_entity_map')->value('owner_id'))->toBe('org-2');
});

it('scopes lookups by tenant and entity type, never by the owner', function () {
    // The owner column must not narrow a lookup. Two owners sharing a tenant is a
    // problem for the host to notice, not a reason to post the same document to
    // the same ledger a second time under a new external id.
    $map = app(EntityMap::class);
    $map->remember(xeroConnection('tenant-a', 'org-1'), EntityType::Bill, 'doc-42', 'invoice-1');

    $other = xeroConnection('tenant-a', 'org-2');

    expect($map->externalId($other, EntityType::Bill, 'doc-42'))->toBe('invoice-1')
        ->and($map->localId($other, EntityType::Bill, 'invoice-1'))->toBe('doc-42');
});

it('forgets a mapping regardless of which owner wrote it', function () {
    $map = app(EntityMap::class);
    $map->remember(xeroConnection('tenant-a', 'org-1'), EntityType::Bill, 'doc-42', 'invoice-1');
    $map->forget(xeroConnection('tenant-a', 'org-2'), EntityType::Bill, 'doc-42');

    expect(DB::table('accounting_entity_map')->count())->toBe(0);
});
