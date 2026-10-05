<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\QuickBooks\QuickBooksConnector;
use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\DisconnectsTenants;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\DisconnectOutcome;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Testing\FakeConnector;

/*
 * Removing the tenant's connection at Xero on a host's Disconnect, and saying which of
 * three things happened: removed now, already gone, or not confirmed. A host clears
 * its own tokens in every case; only Unconfirmed sends the person to Xero to finish.
 */

function secondTenant(): Connection
{
    return new Connection(
        provider: Provider::Xero,
        tenantId: 'tenant-2',
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
        reference: 'org-99',
    );
}

it('is offered by the Xero connector and the fake, not by QuickBooks', function () {
    expect(xeroReader(fakeHttp()))->toBeInstanceOf(DisconnectsTenants::class)
        ->and(new FakeConnector)->toBeInstanceOf(DisconnectsTenants::class)
        ->and(is_subclass_of(QuickBooksConnector::class, DisconnectsTenants::class))->toBeFalse();
});

it('deletes only the connection for this tenant and reports it removed', function () {
    // One Xero user may have connected several organisations to the app; the listing
    // holds all of them and only this tenant's entry is deleted.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/connections'));
    $fake->queueRaw(204, '');

    expect(xeroReader($fake)->disconnectTenant(secondTenant()))->toBe(DisconnectOutcome::Removed)
        ->and($fake->requests)->toHaveCount(2)
        ->and($fake->requests[0]->getMethod())->toBe('GET')
        ->and((string) $fake->requests[0]->getUri())->toBe(XeroConnector::CONNECTIONS_URL)
        ->and($fake->requests[1]->getMethod())->toBe('DELETE')
        ->and((string) $fake->requests[1]->getUri())->toBe(XeroConnector::CONNECTIONS_URL.'/0f3e2d1c-8b7a-4d6e-9c5f-4a3b2c1d0e98')
        ->and($fake->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer access-token');
});

it('reports a tenant missing from a readable listing as not connected, and deletes nothing', function () {
    // The customer already removed the app in Xero, or this is a second click.
    $fake = fakeHttp();
    $fake->queue(200, [['id' => 'conn-other', 'tenantId' => 'tenant-other']]);

    expect(xeroReader($fake)->disconnectTenant(connection()))->toBe(DisconnectOutcome::NotConnected)
        ->and($fake->requests)->toHaveCount(1);
});

it('reports a well-formed listing without the tenant as not connected, but any malformed entry as unconfirmed', function () {
    // NotConnected is a claim that the tenant is gone; only a list of entries that are
    // all readable connections can carry it. One unreadable entry might be this tenant.
    $wellFormed = fakeHttp();
    $wellFormed->queue(200, [['id' => 'conn-other', 'tenantId' => 'tenant-other'], ['id' => 'conn-third', 'tenantId' => 'tenant-third']]);

    $oneBad = fakeHttp();
    $oneBad->queue(200, [['id' => 'conn-other', 'tenantId' => 'tenant-other'], ['id' => 'conn-x']]);

    expect(xeroReader($wellFormed)->disconnectTenant(connection()))->toBe(DisconnectOutcome::NotConnected)
        ->and(xeroReader($oneBad)->disconnectTenant(connection()))->toBe(DisconnectOutcome::Unconfirmed)
        ->and($oneBad->requests)->toHaveCount(1);
});

it('reports an empty listing as not connected', function () {
    $fake = fakeHttp();
    $fake->queue(200, []);

    expect(xeroReader($fake)->disconnectTenant(connection()))->toBe(DisconnectOutcome::NotConnected);
});

it('reports a delete answered 404 as not connected', function () {
    // Removed in Xero between the listing and the delete.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/connections'));
    $fake->queue(404, ['Title' => 'Not Found']);

    expect(xeroReader($fake)->disconnectTenant(connection()))->toBe(DisconnectOutcome::NotConnected);
});

it('reports a refused delete as unconfirmed', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/connections'));
    $fake->queue(400, ['Message' => 'Bad request']);

    expect(xeroReader($fake)->disconnectTenant(connection()))->toBe(DisconnectOutcome::Unconfirmed);
});

it('reports a listing it cannot read as unconfirmed, never as not connected', function (int $status, string $body) {
    $fake = fakeHttp();
    $fake->queueRaw($status, $body, ['Content-Type' => 'application/json']);

    expect(xeroReader($fake)->disconnectTenant(connection()))->toBe(DisconnectOutcome::Unconfirmed)
        ->and($fake->requests)->toHaveCount(1);
})->with([
    'unauthorised' => [401, '{"Title":"Unauthorized"}'],
    'forbidden' => [403, '{"Title":"Forbidden"}'],
    'not a list' => [200, '{"Title":"Something else"}'],
    'an empty object' => [200, '{}'],
    'a null entry' => [200, '[null]'],
    'an empty entry' => [200, '[{}]'],
    'an entry with no tenant id' => [200, '[{"id":"connection-A"}]'],
    'an entry with an empty tenant id' => [200, '[{"id":"connection-A","tenantId":""}]'],
    'an entry with a numeric id' => [200, '[{"id":7,"tenantId":"tenant-other"}]'],
    'a scalar entry' => [200, '["tenant-other"]'],
    'not json' => [200, 'not json'],
    'an entry with no id' => [200, '[{"tenantId":"tenant-1"}]'],
]);

it('reports a rejected refresh as unconfirmed without calling the connections endpoint', function () {
    // A refresh token Xero no longer accepts could mean the customer removed the app,
    // or that it lapsed and the connection is still listed. Nothing can tell which.
    $fake = fakeHttp();
    $fake->queue(400, ['error' => 'invalid_grant']);

    expect(xeroReader($fake)->disconnectTenant(connection(expires: '-1 hour')))->toBe(DisconnectOutcome::Unconfirmed)
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]->getUri()->getHost())->toBe('identity.xero.com');
});

it('refreshes an expired token before listing', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['access_token' => 'fresh-token', 'expires_in' => 1800]);
    $fake->queue(200, [['id' => 'conn-1', 'tenantId' => 'tenant-1']]);
    $fake->queueRaw(204, '');

    expect(xeroReader($fake)->disconnectTenant(connection(expires: '-1 hour')))->toBe(DisconnectOutcome::Removed)
        ->and($fake->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer fresh-token')
        ->and($fake->requests[2]->getHeaderLine('Authorization'))->toBe('Bearer fresh-token');
});

it('never throws: a transport failure is unconfirmed', function () {
    // Nothing queued: the fake HTTP client throws on the listing.
    expect(xeroReader(fakeHttp())->disconnectTenant(connection()))->toBe(DisconnectOutcome::Unconfirmed);
});

it('keeps revoke() true only for a connection removed now', function () {
    $removed = fakeHttp();
    $removed->queue(200, providerResponse('xero/connections'));
    $removed->queueRaw(204, '');

    $gone = fakeHttp();
    $gone->queue(200, []);

    expect(xeroReader($removed)->revoke(connection()))->toBeTrue()
        ->and(xeroReader($gone)->revoke(connection()))->toBeFalse();
});

it('says which outcomes leave nothing for a person to do at the provider', function () {
    expect(DisconnectOutcome::Removed->isSettled())->toBeTrue()
        ->and(DisconnectOutcome::NotConnected->isSettled())->toBeTrue()
        ->and(DisconnectOutcome::Unconfirmed->isSettled())->toBeFalse();
});

it('lets the fake record disconnects, answer not connected the second time, and script an outcome', function () {
    $fake = new FakeConnector;

    expect($fake->disconnectTenant(connection()))->toBe(DisconnectOutcome::Removed)
        ->and($fake->disconnectTenant(connection()))->toBe(DisconnectOutcome::NotConnected)
        ->and($fake->disconnectedTenants)->toBe(['tenant-1']);

    $fake->nextDisconnectOutcome(DisconnectOutcome::Unconfirmed);

    expect($fake->disconnectTenant(secondTenant()))->toBe(DisconnectOutcome::Unconfirmed)
        ->and($fake->disconnectTenant(secondTenant()))->toBe(DisconnectOutcome::Removed)
        ->and($fake->disconnectedTenants)->toBe(['tenant-1', 'tenant-2']);
});
