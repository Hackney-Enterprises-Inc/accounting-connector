<?php

declare(strict_types=1);

use Hei\AccountingConnector\Contracts\ListsContacts;
use Hei\AccountingConnector\Data\Contact;
use Hei\AccountingConnector\Testing\FakeConnector;

it('round-trips every field through toArray and fromArray', function () {
    $contact = new Contact(
        id: 'loser',
        name: 'Officeworks (old)',
        status: 'ARCHIVED',
        isSupplier: true,
        isCustomer: false,
        mergedToContactId: 'winner',
        updatedAt: new DateTimeImmutable('2026-09-20T10:00:00+00:00'),
    );

    expect(Contact::fromArray($contact->toArray()))->toEqual($contact)
        ->and($contact->toArray())->toMatchArray([
            'merged_to_contact_id' => 'winner',
            'updated_at' => '2026-09-20T10:00:00+00:00',
        ]);
});

it('hydrates a payload stored before the merge and updated fields existed', function () {
    $contact = Contact::fromArray([
        'id' => 'abc',
        'name' => 'Officeworks',
        'status' => 'ACTIVE',
        'is_supplier' => true,
        'is_customer' => false,
    ]);

    expect($contact->mergedToContactId)->toBeNull()
        ->and($contact->updatedAt)->toBeNull()
        ->and($contact->isSupplier)->toBeTrue()
        ->and($contact->status)->toBe('ACTIVE');
});

it('reads an unparseable stored date as unknown rather than failing', function () {
    expect(Contact::fromArray(['id' => 'abc', 'name' => 'X', 'updated_at' => 'not a date'])->updatedAt)->toBeNull();
});

it('the fake lists seeded contacts, archived and non-suppliers included, and records each listing', function () {
    $fake = (new FakeConnector)->withContacts(
        new Contact('a', 'Corner Cafe', 'ACTIVE', isSupplier: false),
        new Contact('b', 'Officeworks (old)', 'ARCHIVED', isSupplier: true, mergedToContactId: 'c'),
        new Contact('c', 'Officeworks', 'ACTIVE', isSupplier: true),
    );

    $ids = [];
    foreach ($fake->contacts(connection()) as $contact) {
        $ids[] = $contact->id;
    }

    expect($fake)->toBeInstanceOf(ListsContacts::class)
        ->and($ids)->toBe(['a', 'b', 'c'])
        ->and($fake->contactListings)->toBe([null]);
});

it('the fake honours modifiedSince, and keeps contacts with no updated instant', function () {
    $since = new DateTimeImmutable('2026-09-20T00:00:00+00:00');
    $fake = (new FakeConnector)->withContacts(
        new Contact('old', 'Old', updatedAt: new DateTimeImmutable('2026-09-19T00:00:00+00:00')),
        new Contact('exact', 'Exact', updatedAt: $since),
        new Contact('new', 'New', updatedAt: new DateTimeImmutable('2026-09-21T00:00:00+00:00')),
        new Contact('unknown', 'Unknown'),
    );

    $ids = [];
    foreach ($fake->contacts(connection(), $since) as $contact) {
        $ids[] = $contact->id;
    }

    expect($ids)->toBe(['new', 'unknown'])
        ->and($fake->contactListings)->toEqual([$since]);
});

it('the fake replaces a re-seeded contact and still finds contacts by name', function () {
    $fake = (new FakeConnector)
        ->withContacts(new Contact('a', 'Officeworks', 'ACTIVE'))
        ->withContacts(new Contact('a', 'Officeworks', 'ARCHIVED', mergedToContactId: 'b'), new Contact('b', 'Officeworks', 'ACTIVE'));

    $ids = [];
    foreach ($fake->contacts(connection()) as $contact) {
        $ids[] = $contact->id.':'.$contact->status;
    }

    expect($ids)->toBe(['a:ARCHIVED', 'b:ACTIVE'])
        ->and($fake->findContactByName(connection(), 'officeworks')?->id)->toBe('b');
});

it('the fake listing fails like the other lookups when told to', function () {
    $fake = (new FakeConnector)->withContacts(new Contact('a', 'A'))->failLookups(new RuntimeException('Xero is down'));

    expect(function () use ($fake) {
        foreach ($fake->contacts(connection()) as $contact) {
            // iterate
        }
    })->toThrow(RuntimeException::class, 'Xero is down');
});

it('the fake snapshots a mutable cutoff when iteration starts', function () {
    $since = new DateTime('2026-09-20T00:00:00+00:00');
    $fake = (new FakeConnector)->withContacts(
        new Contact('a', 'A', updatedAt: new DateTimeImmutable('2026-09-21')),
        new Contact('b', 'B', updatedAt: new DateTimeImmutable('2026-09-22')),
    );

    $ids = [];
    foreach ($fake->contacts(connection(), $since) as $contact) {
        $ids[] = $contact->id;
        $since->modify('+1 month');
    }

    expect($ids)->toBe(['a', 'b'])
        ->and($fake->contactListings[0]?->format('Y-m-d'))->toBe('2026-09-20');
});

it('the fake removes the old name when a contact is renamed', function () {
    $fake = (new FakeConnector)
        ->withContacts(new Contact('a', 'Old name'), new Contact('b', 'New name'))
        ->withContacts(new Contact('a', 'New name'));

    expect($fake->findContactByName(connection(), 'Old name'))->toBeNull()
        ->and($fake->findContactByName(connection(), ' NEW NAME ')?->id)->toBe('a')
        ->and(iterator_to_array($fake->contacts(connection())))->toHaveCount(2);
});

it('the fake forgets seeded contacts and recorded listings on flush', function () {
    $fake = (new FakeConnector)->withContacts(new Contact('a', 'A'));
    iterator_to_array($fake->contacts(connection()));

    $fake->flush();

    expect($fake->contactListings)->toBe([])
        ->and(iterator_to_array($fake->contacts(connection())))->toBe([])
        ->and($fake->contactListings)->toBe([null]);
});
