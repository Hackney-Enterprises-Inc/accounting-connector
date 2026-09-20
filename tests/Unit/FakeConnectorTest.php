<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\MoneyDirection;
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

it('records which way an expense went, so a refund can be asserted as a RECEIVE', function () {
    $fake = new FakeConnector(Provider::Xero);

    $refund = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-22'),
        lines: [new LineItem('Refund', unitAmount: Money::cents(1250), accountCode: '400')],
        bankAccount: 'bank-1',
        direction: MoneyDirection::In,
    );

    $fake->createEntity(EntityType::Expense, $refund, connection());
    $fake->createEntity(EntityType::Bill, billFor(), connection());

    expect($fake->createdOf(EntityType::Expense)[0]['direction'])->toBe(MoneyDirection::In)
        ->and($fake->createdOf(EntityType::Bill)[0]['direction'])->toBeNull();
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

it('resolves a contact to a deterministic id and records every call', function () {
    // resolveContact() is on the interface because a host needs the vendor's
    // provider id before any document exists. A host that had to type-hint the
    // concrete XeroConnector to call it could not swap this fake in at all.
    $fake = new FakeConnector(Provider::Xero);

    $first = $fake->resolveContact(ContactData::vendor('Acme Supply'), connection());
    $second = $fake->resolveContact(ContactData::customer('Globex'), connection());

    expect($first)->toBe('fake-contact-1')
        ->and($second)->toBe('fake-contact-2')
        ->and($fake->contacts)->toHaveCount(2)
        ->and($fake->contacts[0]['contact']->name)->toBe('Acme Supply')
        ->and($fake->contacts[0]['contact']->role)->toBe(EntityType::Vendor)
        ->and($fake->contacts[1]['connection']->tenantId)->toBe('tenant-1');
});

it('resolves the same contact to the same id, like an entity map would', function () {
    $fake = new FakeConnector(Provider::Xero);

    $first = $fake->resolveContact(ContactData::vendor('Acme Supply'), connection());
    $again = $fake->resolveContact(ContactData::vendor('acme supply'), connection());
    $other = $fake->resolveContact(ContactData::vendor('Northwind'), connection());

    expect($again)->toBe($first)
        ->and($other)->not->toBe($first)
        ->and($fake->contacts)->toHaveCount(3);

    $fake->flush();

    expect($fake->resolveContact(ContactData::vendor('Acme Supply'), connection()))->toBe('fake-contact-1');
});

it('can be told which ids the next contact resolutions return', function () {
    // For a host asserting against a contact the provider already holds.
    $fake = (new FakeConnector)->nextContactId('contact-existing', 'contact-other');

    expect($fake->resolveContact(ContactData::vendor('Acme Supply'), connection()))->toBe('contact-existing')
        ->and($fake->resolveContact(ContactData::vendor('Globex'), connection()))->toBe('contact-other')
        // Queue exhausted: back to the fake's own sequence.
        ->and($fake->resolveContact(ContactData::vendor('Third Co'), connection()))->toStartWith('fake-contact-');
});

it('fails a contact resolution on the queued failure, like a create', function () {
    // Resolving is how the real connectors create a contact, so the same
    // one-shot failure covers the host's error branch for both.
    $fake = (new FakeConnector)->failNextCreate(new ValidationException('Contact name is required'));

    expect(fn () => $fake->resolveContact(ContactData::vendor('Acme Supply'), connection()))
        ->toThrow(ValidationException::class);

    expect($fake->contacts)->toBeEmpty()
        ->and($fake->resolveContact(ContactData::vendor('Acme Supply'), connection()))->toStartWith('fake-contact-');
});

it('refuses to resolve a contact role the provider does not support', function () {
    $fake = (new FakeConnector(Provider::QuickBooksOnline))->doesNotSupport(EntityType::Vendor);

    expect(fn () => $fake->resolveContact(ContactData::vendor('Acme Supply'), connection()))
        ->toThrow(UnsupportedEntityTypeException::class);
});

it('clears recorded contacts and queued contact ids on flush', function () {
    $fake = (new FakeConnector)->nextContactId('contact-existing');
    $fake->resolveContact(ContactData::vendor('Acme Supply'), connection());

    $fake->flush();

    expect($fake->contacts)->toBeEmpty()
        ->and($fake->resolveContact(ContactData::vendor('Acme Supply'), connection()))->toBe('fake-contact-1');
});
