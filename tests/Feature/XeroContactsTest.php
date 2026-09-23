<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\QuickBooks\QuickBooksConnector;
use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\ListsContacts;
use Hei\AccountingConnector\Data\Contact;
use Hei\AccountingConnector\Exceptions\AuthenticationException;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Exceptions\ValidationException;

/**
 * One Xero contact row as GET Contacts returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function xeroContactRow(string $id, array $overrides = []): array
{
    return $overrides + [
        'ContactID' => $id,
        'ContactStatus' => 'ACTIVE',
        'Name' => 'Contact '.$id,
        'IsSupplier' => true,
        'IsCustomer' => false,
        'UpdatedDateUTC' => '/Date(1758620000000+0000)/',
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function contactsPage(array $rows, ?int $page = null, ?int $pageCount = null): array
{
    $body = ['Contacts' => $rows];

    if ($page !== null) {
        $body['pagination'] = ['page' => $page, 'pageSize' => 100, 'pageCount' => $pageCount, 'itemCount' => 0];
    }

    return $body;
}

/**
 * @return array<int, Contact>
 */
function listAll(iterable $contacts): array
{
    $all = [];

    foreach ($contacts as $contact) {
        $all[] = $contact;
    }

    return $all;
}

it('is an optional capability the Xero connector has', function () {
    expect(xeroReader(fakeHttp()))->toBeInstanceOf(ListsContacts::class);
});

it('pages through every contact 100 at a time, archived included', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage(array_map(fn (int $i): array => xeroContactRow('c-'.$i), range(1, 100)), 1, 2));
    $fake->queue(200, contactsPage([xeroContactRow('c-101'), xeroContactRow('c-102')], 2, 2));

    $contacts = listAll(xeroReader($fake)->contacts(connection()));

    expect($contacts)->toHaveCount(102)
        ->and($contacts[0]->id)->toBe('c-1')
        ->and($contacts[101]->id)->toBe('c-102')
        ->and($fake->requests)->toHaveCount(2)
        ->and(queryOf($fake, 0))->toMatchArray(['page' => '1', 'pageSize' => '100', 'includeArchived' => 'true', 'order' => 'ContactID ASC'])
        ->and(queryOf($fake, 1)['page'])->toBe('2')
        ->and((string) $fake->requests[0]->getUri())->toContain('/Contacts?');
});

it('stops on a short page when Xero sends no pagination object', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage(array_map(fn (int $i): array => xeroContactRow('c-'.$i), range(1, 100))));
    $fake->queue(200, contactsPage([xeroContactRow('c-101')]));

    expect(listAll(xeroReader($fake)->contacts(connection())))->toHaveCount(101)
        ->and($fake->requests)->toHaveCount(2)
        ->and($fake->isDrained())->toBeTrue();
});

it('lists every contact, not only suppliers, with the supplier flag as information', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage([
        xeroContactRow('card-only-vendor', ['IsSupplier' => false, 'Name' => 'Corner Cafe']),
        xeroContactRow('billed-supplier', ['IsSupplier' => true]),
        xeroContactRow('customer', ['IsSupplier' => false, 'IsCustomer' => true]),
    ], 1, 1));

    $contacts = listAll(xeroReader($fake)->contacts(connection()));

    expect(array_map(fn (Contact $c): string => $c->id, $contacts))->toBe(['card-only-vendor', 'billed-supplier', 'customer'])
        ->and($contacts[0]->isSupplier)->toBeFalse()
        ->and($contacts[1]->isSupplier)->toBeTrue()
        ->and($contacts[2]->isCustomer)->toBeTrue()
        ->and(queryOf($fake, 0))->not->toHaveKey('where');
});

it('carries status, the merge target and the updated instant', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage([
        xeroContactRow('loser', ['ContactStatus' => 'ARCHIVED', 'MergedToContactID' => 'winner']),
        xeroContactRow('winner'),
        xeroContactRow('gdpr', ['ContactStatus' => 'GDPRREQUEST', 'MergedToContactID' => '00000000-0000-0000-0000-000000000000']),
        xeroContactRow('bare', ['MergedToContactID' => '', 'UpdatedDateUTC' => null]),
    ], 1, 1));

    [$loser, $winner, $gdpr, $bare] = listAll(xeroReader($fake)->contacts(connection()));

    expect($loser->status)->toBe('ARCHIVED')
        ->and($loser->isActive())->toBeFalse()
        ->and($loser->mergedToContactId)->toBe('winner')
        ->and($winner->mergedToContactId)->toBeNull()
        ->and($gdpr->status)->toBe('GDPRREQUEST')
        ->and($gdpr->mergedToContactId)->toBeNull()
        ->and($bare->mergedToContactId)->toBeNull()
        ->and($bare->updatedAt)->toBeNull()
        ->and($winner->updatedAt?->format(DATE_ATOM))->toBe((new DateTimeImmutable('@1758620000'))->format(DATE_ATOM));
});

it('sends If-Modified-Since in UTC when asked for changes, and treats a 304 as nothing changed', function () {
    $fake = fakeHttp();
    $fake->queue(304, []);

    $since = new DateTimeImmutable('2026-09-20 10:00:00', new DateTimeZone('Australia/Sydney'));

    $contacts = listAll(xeroReader($fake)->contacts(connection(), $since));

    expect($contacts)->toBe([])
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]->getHeaderLine('If-Modified-Since'))->toBe('2026-09-20T00:00:00');
});

it('sends no If-Modified-Since for a full listing', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage([], 1, 0));

    listAll(xeroReader($fake)->contacts(connection()));

    expect($fake->requests[0]->hasHeader('If-Modified-Since'))->toBeFalse();
});

it('makes no request until iterated, and none past where the caller stops', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage(array_map(fn (int $i): array => xeroContactRow('c-'.$i), range(1, 100)), 1, 3));

    $listing = xeroReader($fake)->contacts(connection());

    expect($fake->requests)->toHaveCount(0);

    foreach ($listing as $contact) {
        break;
    }

    expect($fake->requests)->toHaveCount(1);
});

it('raises a provider failure from the iteration', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage(array_map(fn (int $i): array => xeroContactRow('c-'.$i), range(1, 100)), 1, 2));
    $fake->queue(500, []);

    $seen = [];

    expect(function () use ($fake, &$seen) {
        foreach (xeroReader($fake)->contacts(connection()) as $contact) {
            $seen[] = $contact->id;
        }
    })->toThrow(ServerException::class)
        ->and($seen)->toHaveCount(100);
});

it('keeps the contact-by-name lookup on the same mapping', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [xeroContactRow('abc', ['Name' => 'Officeworks', 'MergedToContactID' => 'def'])]]);

    $contact = xeroReader($fake)->findContactByName(connection(), 'Officeworks');

    expect($contact?->id)->toBe('abc')
        ->and($contact?->mergedToContactId)->toBe('def')
        ->and($contact?->updatedAt)->not->toBeNull();
});

it('QuickBooks does not offer the listing', function () {
    expect(XeroConnector::class)->toImplement(ListsContacts::class)
        ->and(QuickBooksConnector::class)->not->toImplement(ListsContacts::class);
});

it('fetches an empty terminal page for an exact page-size multiple without metadata', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage(array_map(fn (int $i): array => xeroContactRow('c-'.$i), range(1, 100))));
    $fake->queue(200, contactsPage([]));

    expect(listAll(xeroReader($fake)->contacts(connection())))->toHaveCount(100)
        ->and($fake->requests)->toHaveCount(2);
});

it('follows pagination metadata even for short pages and stops on an empty page', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage([xeroContactRow('a')], 1, 10));
    $fake->queue(200, contactsPage([], 2, 10));

    expect(listAll(xeroReader($fake)->contacts(connection())))->toHaveCount(1)
        ->and($fake->requests)->toHaveCount(2);
});

it('uses the same UTC cutoff and tenant on every page despite caller date mutation', function () {
    $fake = fakeHttp();
    $fake->queue(200, contactsPage([xeroContactRow('a')], 1, 2));
    $fake->queue(200, contactsPage([xeroContactRow('b')], 2, 2));
    $since = new DateTime('2026-09-20T10:00:00+10:00');

    foreach (xeroReader($fake)->contacts(connection(), $since) as $contact) {
        $since->modify('+1 month');
    }

    expect($fake->requests)->toHaveCount(2);

    foreach ($fake->requests as $request) {
        expect($request->getHeaderLine('If-Modified-Since'))->toBe('2026-09-20T00:00:00')
            ->and($request->getHeaderLine('xero-tenant-id'))->toBe('tenant-1')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer access-token');
    }
});

it('refreshes between pages using the rotated refresh token', function () {
    $fake = fakeHttp();
    // A token inside the expiry leeway forces the next refresh without sleeping.
    $fake->queue(200, ['access_token' => 'first', 'refresh_token' => 'rotated', 'expires_in' => 30]);
    $fake->queue(200, contactsPage([xeroContactRow('a')], 1, 2));
    $fake->queue(200, ['access_token' => 'second', 'refresh_token' => 'rotated-again', 'expires_in' => 1800]);
    $fake->queue(200, contactsPage([xeroContactRow('b')], 2, 2));

    expect(listAll(xeroReader($fake)->contacts(connection(expires: '-1 hour'))))->toHaveCount(2)
        ->and($fake->requests)->toHaveCount(4)
        ->and($fake->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer first')
        ->and($fake->requests[3]->getHeaderLine('Authorization'))->toBe('Bearer second');

    parse_str($fake->bodies[2], $refresh);
    expect($refresh['refresh_token'])->toBe('rotated');
});

it('throws a revoked connection error from iteration when refresh is rejected', function () {
    $fake = fakeHttp();
    $fake->queue(400, ['error' => 'invalid_grant']);
    $listing = xeroReader($fake)->contacts(connection(expires: '-1 hour'));

    expect($fake->requests)->toBe([]);
    expect(fn () => listAll($listing))->toThrow(ConnectionRevokedException::class);
    expect($fake->requests)->toHaveCount(1);
});

it('skips unusable contact ids without truncating subsequent pages', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [null, 'invalid', [], ['ContactID' => ''], ['ContactID' => 123]], 'pagination' => ['pageCount' => 2]]);
    $fake->queue(200, contactsPage([xeroContactRow('valid', ['ContactStatus' => '', 'UpdatedDateUTC' => 'invalid'])], 2, 2));

    $contacts = listAll(xeroReader($fake)->contacts(connection()));

    expect($contacts)->toHaveCount(1)
        ->and($contacts[0]->id)->toBe('valid')
        ->and($contacts[0]->status)->toBeNull()
        ->and($contacts[0]->updatedAt)->toBeNull();
});

it('propagates rejected list requests instead of treating them as empty results', function (int $status, string $exception) {
    $fake = fakeHttp();
    $fake->queue($status, ['Message' => 'Request rejected']);

    expect(fn () => listAll(xeroReader($fake)->contacts(connection())))->toThrow($exception)
        ->and($fake->requests)->toHaveCount(1);
})->with([
    'invalid request' => [400, ValidationException::class],
    'revoked grant' => [401, ConnectionRevokedException::class],
    'missing scope' => [403, AuthenticationException::class],
]);
