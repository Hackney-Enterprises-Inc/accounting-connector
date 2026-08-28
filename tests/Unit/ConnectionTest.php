<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Enums\Provider;

it('treats a token expiring within the leeway as already expired', function () {
    // The point of the leeway: a token with four seconds left passes a naive
    // expiry check and then 401s halfway through the request it was checked for.
    $almostGone = connection(expires: '+30 seconds');

    expect($almostGone->isExpired())->toBeTrue()
        ->and($almostGone->isExpired(leewaySeconds: 5))->toBeFalse();
});

it('treats a connection with no expiry as expired', function () {
    expect(connection(expires: null)->isExpired())->toBeTrue();
});

it('keeps the old refresh token when a provider does not return a new one', function () {
    // Xero does not always send a refresh token back on refresh. Blanking the field
    // would kill a connection that is working perfectly well.
    $refreshed = connection()->withTokens(new TokenSet(
        accessToken: 'new-access',
        refreshToken: null,
    ));

    expect($refreshed->accessToken)->toBe('new-access')
        ->and($refreshed->refreshToken)->toBe('refresh-token');
});

it('takes the rotated refresh token when the provider does send one', function () {
    // Intuit rotates on every refresh, and dropping the new one kills the connection.
    $refreshed = connection()->withTokens(new TokenSet(
        accessToken: 'new-access',
        refreshToken: 'rotated-refresh',
    ));

    expect($refreshed->refreshToken)->toBe('rotated-refresh');
});

it('carries settings and reference across a refresh', function () {
    $refreshed = connection(settings: ['bank_account' => 'acc-1'])
        ->withTokens(new TokenSet(accessToken: 'new-access'));

    expect($refreshed->setting('bank_account'))->toBe('acc-1')
        ->and($refreshed->reference)->toBe('org-99');
});

it('keeps tokens out of the array form unless explicitly asked', function () {
    // A connection dropped into a log context must not leak credentials.
    $connection = connection();

    expect($connection->toArray())->not->toHaveKey('access_token')
        ->and($connection->toArray())->not->toHaveKey('refresh_token')
        ->and($connection->toArray(includeTokens: true))->toHaveKey('access_token');
});

it('never puts a raw tenant id in a cache key', function () {
    // A Xero tenant id in a shared cache namespace identifies the customer.
    $key = connection()->cacheKey('chart_of_accounts');

    expect($key)->not->toContain('tenant-1')
        ->and($key)->toStartWith('accounting_connector.xero.')
        ->and($key)->toEndWith('.chart_of_accounts');
});

it('gives two tenants different cache keys for the same lookup', function () {
    $a = new Connection(Provider::Xero, 'tenant-a', 'token');
    $b = new Connection(Provider::Xero, 'tenant-b', 'token');

    expect($a->cacheKey('bank_accounts'))->not->toBe($b->cacheKey('bank_accounts'));
});

it('round-trips through the stored array shape', function () {
    $original = connection(settings: ['bank_account' => 'acc-1']);

    $restored = Connection::fromArray($original->toArray(includeTokens: true));

    expect($restored->provider)->toBe(Provider::Xero)
        ->and($restored->tenantId)->toBe('tenant-1')
        ->and($restored->accessToken)->toBe('access-token')
        ->and($restored->refreshToken)->toBe('refresh-token')
        ->and($restored->setting('bank_account'))->toBe('acc-1')
        ->and($restored->expiresAt?->getTimestamp())->toBe($original->expiresAt?->getTimestamp());
});

it('knows when only a reconnect will help', function () {
    $lapsed = new Connection(
        provider: Provider::QuickBooksOnline,
        tenantId: 'realm-1',
        accessToken: 'access',
        refreshToken: 'refresh',
        refreshTokenExpiresAt: (new DateTimeImmutable)->modify('-1 day'),
    );

    expect($lapsed->isRefreshExpired())->toBeTrue()
        ->and(connection()->isRefreshExpired())->toBeFalse();
});

it('reads relative expiry out of a provider token response', function () {
    $now = new DateTimeImmutable('2026-08-21 12:00:00');

    $tokens = TokenSet::fromResponse([
        'access_token' => 'a',
        'refresh_token' => 'r',
        'expires_in' => 3600,
        'x_refresh_token_expires_in' => 8726400,
    ], $now);

    expect($tokens->expiresAt?->format('H:i:s'))->toBe('13:00:00')
        ->and($tokens->refreshTokenExpiresAt?->format('Y-m-d'))->toBe('2026-11-30');
});
