<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Support\ArrayEntityMap;
use Hei\AccountingConnector\Support\NullEntityMap;

it('records and reads back a correspondence', function () {
    $map = new ArrayEntityMap;
    $map->remember(connection(), EntityType::Bill, 'doc-42', 'invoice-1');

    expect($map->externalId(connection(), EntityType::Bill, 'doc-42'))->toBe('invoice-1')
        ->and($map->localId(connection(), EntityType::Bill, 'invoice-1'))->toBe('doc-42');
});

it('never returns one tenant external id for another tenant local id', function () {
    // The bug this guards is not theoretical: two organizations will both hold a
    // local id of '1', and crossing them posts a customer's money into a stranger's
    // ledger.
    $map = new ArrayEntityMap;

    $a = new Connection(Provider::Xero, 'tenant-a', 'token');
    $b = new Connection(Provider::Xero, 'tenant-b', 'token');

    $map->remember($a, EntityType::Bill, '1', 'invoice-belonging-to-a');

    expect($map->externalId($a, EntityType::Bill, '1'))->toBe('invoice-belonging-to-a')
        ->and($map->externalId($b, EntityType::Bill, '1'))->toBeNull();
});

it('keeps the two providers apart for the same tenant string', function () {
    $map = new ArrayEntityMap;

    $xero = new Connection(Provider::Xero, 'same-id', 'token');
    $qbo = new Connection(Provider::QuickBooksOnline, 'same-id', 'token');

    $map->remember($xero, EntityType::Bill, 'doc-1', 'xero-invoice');

    expect($map->externalId($qbo, EntityType::Bill, 'doc-1'))->toBeNull();
});

it('keeps entity types apart for the same local id', function () {
    // One document can post as both a bill and an expense.
    $map = new ArrayEntityMap;
    $map->remember(connection(), EntityType::Bill, 'doc-1', 'invoice-1');
    $map->remember(connection(), EntityType::Expense, 'doc-1', 'txn-1');

    expect($map->externalId(connection(), EntityType::Bill, 'doc-1'))->toBe('invoice-1')
        ->and($map->externalId(connection(), EntityType::Expense, 'doc-1'))->toBe('txn-1');
});

it('replaces rather than duplicates when a local id is remembered again', function () {
    $map = new ArrayEntityMap;
    $map->remember(connection(), EntityType::Bill, 'doc-1', 'invoice-old');
    $map->remember(connection(), EntityType::Bill, 'doc-1', 'invoice-new');

    expect($map->externalId(connection(), EntityType::Bill, 'doc-1'))->toBe('invoice-new')
        // The stale reverse entry must go too, or a webhook for the old id resolves
        // to a document that no longer points at it.
        ->and($map->localId(connection(), EntityType::Bill, 'invoice-old'))->toBeNull()
        ->and($map->localId(connection(), EntityType::Bill, 'invoice-new'))->toBe('doc-1');
});

it('forgets both directions', function () {
    $map = new ArrayEntityMap;
    $map->remember(connection(), EntityType::Bill, 'doc-1', 'invoice-1');
    $map->forget(connection(), EntityType::Bill, 'doc-1');

    expect($map->externalId(connection(), EntityType::Bill, 'doc-1'))->toBeNull()
        ->and($map->localId(connection(), EntityType::Bill, 'invoice-1'))->toBeNull();
});

it('lets the null map remember nothing without complaining', function () {
    $map = new NullEntityMap;
    $map->remember(connection(), EntityType::Bill, 'doc-1', 'invoice-1');

    expect($map->externalId(connection(), EntityType::Bill, 'doc-1'))->toBeNull();
});
