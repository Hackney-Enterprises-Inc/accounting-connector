<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Exceptions\UnsupportedEntityTypeException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Testing\FakeConnector;

it('records what a consuming application asked it to create', function () {
    $fake = new FakeConnector(Provider::Xero);

    $id = $fake->createEntity(EntityType::Bill, billFor(), connection(), 'sync-bill-doc-42');

    expect($id)->toBe('fake-bill-1')
        ->and($fake->created)->toHaveCount(1)
        ->and($fake->created[0]['idempotency_key'])->toBe('sync-bill-doc-42')
        ->and($fake->createdOf(EntityType::Bill))->toHaveCount(1)
        ->and($fake->createdOf(EntityType::Expense))->toBeEmpty();
});

it('can be told to fail, so a host error branch gets covered', function () {
    $fake = (new FakeConnector)->failNextCreate(new ValidationException('Bad account'));

    expect(fn () => $fake->createEntity(EntityType::Bill, billFor(), connection()))
        ->toThrow(ValidationException::class);

    // The failure is one-shot: the next call succeeds again.
    expect($fake->createEntity(EntityType::Bill, billFor(), connection()))->not->toBeNull();
});

it('can simulate a provider accepting a post but returning no id', function () {
    $fake = (new FakeConnector)->nextCreateReturnsNoId();

    expect($fake->createEntity(EntityType::Bill, billFor(), connection()))->toBeNull()
        ->and($fake->created)->toHaveCount(1);
});

it('can simulate a failed attachment without failing the post', function () {
    $fake = (new FakeConnector)->nextAttachment(AttachmentResult::failed('too large'));

    $result = $fake->attach(
        EntityType::Bill,
        'invoice-1',
        new Attachment('receipt.pdf', 'BYTES', 'application/pdf'),
        connection(),
    );

    expect($result->uploaded)->toBeFalse()
        ->and($fake->attached)->toHaveCount(1);
});

it('can simulate a provider that does not support an entity type', function () {
    $fake = (new FakeConnector)->doesNotSupport(EntityType::Journal);

    expect($fake->supports(EntityType::Journal))->toBeFalse()
        ->and($fake->supports(EntityType::Bill))->toBeTrue();
});

it('refuses an unsupported entity type on create, like the real connector', function () {
    $fake = (new FakeConnector)->doesNotSupport(EntityType::Bill);

    expect(fn () => $fake->createEntity(EntityType::Bill, billFor(), connection()))
        ->toThrow(UnsupportedEntityTypeException::class);

    expect($fake->created)->toBeEmpty();
});

it('refuses a payload describing a different entity, like the real connector', function () {
    // A host test that posts a BillData as an expense must fail here, not in
    // production against the real connector.
    expect(fn () => (new FakeConnector)->createEntity(EntityType::Expense, billFor(), connection()))
        ->toThrow(InvalidPayloadException::class, 'Cannot create a "expense": the payload describes a "bill"');

    expect(fn () => (new FakeConnector)->updateEntity(EntityType::Expense, 'x-1', billFor(), connection()))
        ->toThrow(InvalidPayloadException::class);
});

it('refuses a raw payload built for another provider, like the real connector', function () {
    $raw = RawPayload::forQuickBooks(EntityType::Bill, ['VendorRef' => ['value' => '1']]);

    expect(fn () => (new FakeConnector(Provider::Xero))->createEntity(EntityType::Bill, $raw, connection()))
        ->toThrow(InvalidPayloadException::class, 'cannot be posted to Xero');
});

it('can simulate an unidentifiable tenant', function () {
    expect((new FakeConnector)->withoutTenantInfo()->tenantInfo(connection()))->toBeNull()
        ->and((new FakeConnector)->tenantInfo(connection())?->name)->toBe('Fake Company');
});

it('rotates tokens on refresh, like a real provider', function () {
    $fake = new FakeConnector;

    $refreshed = $fake->refresh(connection());

    expect($refreshed->accessToken)->not->toBe('access-token')
        ->and($refreshed->refreshToken)->not->toBe('refresh-token')
        ->and($fake->refreshed)->toHaveCount(1);
});

it('forgets everything on flush', function () {
    $fake = new FakeConnector;
    $fake->createEntity(EntityType::Bill, billFor(), connection());
    $fake->flush();

    expect($fake->created)->toBeEmpty()
        ->and($fake->createEntity(EntityType::Bill, billFor(), connection()))->toBe('fake-bill-1');
});

it('clears queued failures and unsupported types on flush', function () {
    // A fake reused across tests must not throw a stale failure queued by the
    // previous one.
    $fake = (new FakeConnector)
        ->failNextCreate(new ValidationException('Bad account'))
        ->nextAttachment(AttachmentResult::failed('too large'))
        ->doesNotSupport(EntityType::Journal);

    $fake->flush();

    $attached = $fake->attach(
        EntityType::Bill,
        'invoice-1',
        new Attachment('receipt.pdf', 'BYTES', 'application/pdf'),
        connection(),
    );

    expect($fake->createEntity(EntityType::Bill, billFor(), connection()))->toStartWith('fake-bill')
        ->and($fake->supports(EntityType::Journal))->toBeTrue()
        ->and($attached->uploaded)->toBeTrue();
});

it('can simulate a provider outage on every lookup', function () {
    $fake = (new FakeConnector)->failLookups(new ServerException('Xero is down'));

    expect(fn () => $fake->chartOfAccounts(connection()))->toThrow(ServerException::class)
        ->and(fn () => $fake->trackingCategories(connection()))->toThrow(ServerException::class)
        ->and(fn () => $fake->refreshLookups(connection()))->toThrow(ServerException::class);

    // Unlike failNextCreate() this persists, then clears on request.
    $fake->failLookups(null);

    expect($fake->chartOfAccounts(connection()))->toBe([]);
});
