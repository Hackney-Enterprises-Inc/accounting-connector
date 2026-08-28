<?php

declare(strict_types=1);

use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Support\IdempotencyKey;

it('builds the same key for the same document every time', function () {
    expect(IdempotencyKey::for(EntityType::Bill, 'doc-42'))
        ->toBe('sync-bill-doc-42')
        ->and(IdempotencyKey::for(EntityType::Bill, 'doc-42'))
        ->toBe(IdempotencyKey::for(EntityType::Bill, 'doc-42'));
});

it('gives one document a different key per entity type', function () {
    // A document that posts as both a bill and an expense must not have the second
    // post deduped against the first.
    expect(IdempotencyKey::for(EntityType::Bill, 'doc-42'))
        ->not->toBe(IdempotencyKey::for(EntityType::Expense, 'doc-42'));
});

it('leaves a short key alone so it stays readable in a provider audit log', function () {
    $key = 'sync-bill-doc-42';

    expect(IdempotencyKey::truncate($key, Provider::Xero))->toBe($key)
        ->and(IdempotencyKey::truncate($key, Provider::QuickBooksOnline))->toBe($key);
});

it('fits a long key inside each provider limit', function () {
    $key = 'sync-bill-'.str_repeat('a', 300);

    expect(strlen(IdempotencyKey::truncate($key, Provider::Xero)))
        ->toBeLessThanOrEqual(IdempotencyKey::XERO_MAX_LENGTH)
        ->and(strlen(IdempotencyKey::truncate($key, Provider::QuickBooksOnline)))
        ->toBeLessThanOrEqual(IdempotencyKey::QUICKBOOKS_MAX_LENGTH);
});

it('keeps two keys distinct when they differ only past the provider limit', function () {
    // The trap this guards. Intuit's requestid caps at 50 characters and answers a
    // repeated one by silently replaying the first response rather than erroring, so
    // a naive substr() would make the second document's post vanish without a trace.
    $prefix = str_repeat('x', 60);

    $a = IdempotencyKey::truncate($prefix.'-document-1', Provider::QuickBooksOnline);
    $b = IdempotencyKey::truncate($prefix.'-document-2', Provider::QuickBooksOnline);

    expect($a)->not->toBe($b)
        ->and(strlen($a))->toBeLessThanOrEqual(50);
});

it('truncates deterministically so a retry produces the same key', function () {
    $key = 'sync-expense-'.str_repeat('z', 200);

    expect(IdempotencyKey::truncate($key, Provider::QuickBooksOnline))
        ->toBe(IdempotencyKey::truncate($key, Provider::QuickBooksOnline));
});
