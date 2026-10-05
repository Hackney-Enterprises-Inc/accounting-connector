<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\ConnectionRepository;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Enums\DisconnectOutcome;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\AccountingConnectorException;
use Hei\AccountingConnector\Laravel\DatabaseConnectionRepository;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Psr\Log\AbstractLogger;

function xeroFor(string $owner = 'org-1', array $settings = []): Connection
{
    return new Connection(
        provider: Provider::Xero,
        tenantId: 'tenant-abc',
        accessToken: 'access-secret',
        refreshToken: 'refresh-secret',
        expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
        refreshTokenExpiresAt: (new DateTimeImmutable)->modify('+60 days'),
        settings: $settings,
        reference: $owner,
    );
}

it('warns loudly when a refresh matches no stored row instead of losing tokens silently', function () {
    // Intuit has already retired the refresh token this one replaced. A silent
    // zero-row update is a connection that dies days later with no obvious cause.
    $logger = new class extends AbstractLogger
    {
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };

    $repo = new DatabaseConnectionRepository(
        resolver: app('db'),
        encrypter: app('encrypter'),
        logger: $logger,
    );

    // Never saved, so there is no row for persist() to hit.
    $repo->persist(xeroFor('org-never-saved'));

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('error')
        ->and($logger->records[0]['message'])->toContain('NOT persisted')
        ->and($logger->records[0]['context']['connection'])->toBe('org-never-saved');

    // And stays quiet when the row exists.
    $repo->save(xeroFor('org-saved'));
    $repo->persist(xeroFor('org-saved'));

    expect($logger->records)->toHaveCount(1);
});

it('stores and reads back a connection', function () {
    $repo = app(ConnectionRepository::class);
    $repo->save(xeroFor('org-1', ['bank_account' => 'acc-9']));

    $found = $repo->find('org-1', Provider::Xero);

    expect($found?->tenantId)->toBe('tenant-abc')
        ->and($found?->accessToken)->toBe('access-secret')
        ->and($found?->refreshToken)->toBe('refresh-secret')
        ->and($found?->setting('bank_account'))->toBe('acc-9')
        ->and($found?->reference)->toBe('org-1');
});

it('never writes a token to the database in plaintext', function () {
    // The whole point of the encrypter being here. A database dump must not be a
    // set of working Xero credentials.
    app(ConnectionRepository::class)->save(xeroFor());

    $row = DB::table('accounting_connections')->first();

    expect($row->access_token)->not->toContain('access-secret')
        ->and($row->refresh_token)->not->toContain('refresh-secret')
        ->and($row->access_token)->not->toBeEmpty();
});

it('keeps one connection per owner per provider', function () {
    $repo = app(ConnectionRepository::class);

    $repo->save(xeroFor('org-1'));
    $repo->save(xeroFor('org-1'));

    expect(DB::table('accounting_connections')->count())->toBe(1);
});

it('keeps two owners apart', function () {
    $repo = app(ConnectionRepository::class);
    $repo->save(xeroFor('org-1'));
    $repo->save(xeroFor('org-2'));

    expect($repo->find('org-1', Provider::Xero)?->reference)->toBe('org-1')
        ->and($repo->find('org-2', Provider::Xero)?->reference)->toBe('org-2')
        ->and($repo->find('org-3', Provider::Xero))->toBeNull();
});

it('holds both providers for one owner in the same table', function () {
    // The point of the consolidation: a second provider is a row, not four columns.
    $repo = app(ConnectionRepository::class);
    $repo->save(xeroFor('org-1'));
    $repo->save(new Connection(
        provider: Provider::QuickBooksOnline,
        tenantId: 'realm-9',
        accessToken: 'qbo-access',
        refreshToken: 'qbo-refresh',
        reference: 'org-1',
    ));

    expect($repo->forOwner('org-1'))->toHaveCount(2)
        ->and($repo->find('org-1', Provider::QuickBooksOnline)?->tenantId)->toBe('realm-9');
});

it('ignores rows for providers the package has no connector for', function () {
    // AccountingPipe keeps Google Sheets in the same table and reads it with its own
    // service. The package must step over those rows, not choke on them.
    app(ConnectionRepository::class)->save(xeroFor('org-1'));

    DB::table('accounting_connections')->insert([
        'owner_id' => 'org-1',
        'provider' => 'google_sheets',
        'tenant_id' => 'spreadsheet-123',
        'access_token' => 'whatever',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(ConnectionRepository::class)->forOwner('org-1'))->toHaveCount(1)
        ->and(DB::table('accounting_connections')->count())->toBe(2);
});

it('persists rotated tokens without touching settings', function () {
    // A refresh racing a settings update must not roll the settings back.
    $repo = app(ConnectionRepository::class);
    $repo->save(xeroFor('org-1', ['bank_account' => 'acc-9']));

    $refreshed = xeroFor('org-1')->withTokens(new TokenSet(
        accessToken: 'rotated-access',
        refreshToken: 'rotated-refresh',
        expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
    ));

    app(ConnectionStore::class)->persist($refreshed);

    $found = $repo->find('org-1', Provider::Xero);

    expect($found?->accessToken)->toBe('rotated-access')
        ->and($found?->refreshToken)->toBe('rotated-refresh')
        ->and($found?->setting('bank_account'))->toBe('acc-9');
});

it('binds the same object as repository and refresh store', function () {
    // This is what makes rotated QuickBooks refresh tokens persist with no glue from
    // the host, which is the single most consequential thing to get wrong here.
    expect(app(ConnectionStore::class))->toBeInstanceOf(DatabaseConnectionRepository::class)
        ->and(app(ConnectionRepository::class))->toBeInstanceOf(DatabaseConnectionRepository::class);
});

it('marks a connection revoked and drops the useless tokens', function () {
    $repo = app(ConnectionRepository::class);
    $repo->save(xeroFor('org-1'));

    $repo->markRevoked('org-1', Provider::Xero, 'invalid_grant');

    $row = DB::table('accounting_connections')->first();

    expect($row->status)->toBe('revoked')
        ->and($row->revoked_reason)->toBe('invalid_grant')
        ->and($row->access_token)->toBeNull()
        ->and($row->refresh_token)->toBeNull()
        // The row survives so a host can show "reconnect Xero" against this tenant.
        ->and(DB::table('accounting_connections')->count())->toBe(1)
        ->and($repo->find('org-1', Provider::Xero))->toBeNull();
});

it('clears a revocation when the customer reconnects', function () {
    $repo = app(ConnectionRepository::class);
    $repo->save(xeroFor('org-1'));
    $repo->markRevoked('org-1', Provider::Xero, 'invalid_grant');

    $repo->save(xeroFor('org-1'));

    expect($repo->find('org-1', Provider::Xero))->not->toBeNull()
        ->and(DB::table('accounting_connections')->first()->status)->toBe('active');
});

it('treats an undecryptable token as a connection needing reconnection', function () {
    // An APP_KEY rotation makes every stored token unreadable. That must become
    // "reconnect", not a fatal error on every queue job.
    app(ConnectionRepository::class)->save(xeroFor('org-1'));

    DB::table('accounting_connections')->update(['access_token' => 'not-valid-ciphertext']);

    expect(app(ConnectionRepository::class)->find('org-1', Provider::Xero))->toBeNull();
});

it('reports whether an owner is connected', function () {
    $repo = app(DatabaseConnectionRepository::class);

    expect($repo->isConnected('org-1', Provider::Xero))->toBeFalse();

    $repo->save(xeroFor('org-1'));

    expect($repo->isConnected('org-1', Provider::Xero))->toBeTrue();
});

it('forgets a connection on disconnect', function () {
    $repo = app(ConnectionRepository::class);
    $repo->save(xeroFor('org-1'));
    $repo->forget('org-1', Provider::Xero);

    expect(DB::table('accounting_connections')->count())->toBe(0);
});

it('refuses to store a connection with no owner', function () {
    $orphan = new Connection(Provider::Xero, 'tenant-abc', 'token');

    expect(fn () => app(ConnectionRepository::class)->save($orphan))
        ->toThrow(AccountingConnectorException::class);
});

it('encrypts byte-compatibly with Eloquent own encrypted cast', function () {
    // A host will put its own model over this table. If the package serialized and
    // the cast did not, the two would silently disagree and a token would fail to
    // decrypt only once something tried to use it.
    app(ConnectionRepository::class)->save(xeroFor('org-1'));

    $ciphertext = DB::table('accounting_connections')->value('access_token');

    expect(Crypt::decrypt($ciphertext, false))->toBe('access-secret');
});

/**
 * A logger that keeps what it was given, for asserting on level and wording.
 */
function keepingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

function tenantConnection(string $tenant, string $access, string $refresh, string $owner = 'org-reconnect'): Connection
{
    return new Connection(
        provider: Provider::Xero,
        tenantId: $tenant,
        accessToken: $access,
        refreshToken: $refresh,
        expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
        reference: $owner,
    );
}

it('persists nothing from a refresh for a tenant the owner has since reconnected away from', function () {
    // Save tenant A, reconnect the owner to tenant B, then A's refresh that was
    // already in flight lands. Matching on the owner alone wrote A's tokens under
    // B's tenant id: B's working credentials were overwritten.
    $logger = keepingLogger();
    $repo = new DatabaseConnectionRepository(resolver: app('db'), encrypter: app('encrypter'), logger: $logger);

    $repo->save(tenantConnection('tenant-A', 'access-A', 'refresh-A'));
    $inFlight = $repo->find('org-reconnect', Provider::Xero);
    $repo->save(tenantConnection('tenant-B', 'access-B', 'refresh-B'));

    $repo->persist($inFlight->withTokens(new TokenSet('refreshed-access-A', 'refreshed-refresh-A', (new DateTimeImmutable)->modify('+30 minutes'))));

    $stored = $repo->find('org-reconnect', Provider::Xero);
    expect($stored->tenantId)->toBe('tenant-B')
        ->and($stored->accessToken)->toBe('access-B')
        ->and($stored->refreshToken)->toBe('refresh-B')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toContain('no longer')
        ->and($logger->records[0]['message'])->not->toContain('will die')
        ->and(json_encode($logger->records))->not->toContain('refreshed-access-A')
        ->and(json_encode($logger->records))->not->toContain('refreshed-refresh-A');
});

it('persists a refresh for the tenant the owner is still connected to (control)', function () {
    $logger = keepingLogger();
    $repo = new DatabaseConnectionRepository(resolver: app('db'), encrypter: app('encrypter'), logger: $logger);

    $repo->save(tenantConnection('tenant-B', 'access-B', 'refresh-B'));
    $current = $repo->find('org-reconnect', Provider::Xero);

    $repo->persist($current->withTokens(new TokenSet('refreshed-access-B', 'refreshed-refresh-B', (new DateTimeImmutable)->modify('+30 minutes'))));

    expect($repo->find('org-reconnect', Provider::Xero)->accessToken)->toBe('refreshed-access-B')
        ->and($logger->records)->toBe([]);
});

it('persists nothing into a row that is no longer active', function (string $status) {
    // A refresh racing a revocation, or a host's own disconnect, must not put live
    // tokens back on a row that was deliberately emptied.
    $logger = keepingLogger();
    $repo = new DatabaseConnectionRepository(resolver: app('db'), encrypter: app('encrypter'), logger: $logger);

    $repo->save(tenantConnection('tenant-A', 'access-A', 'refresh-A'));
    $inFlight = $repo->find('org-reconnect', Provider::Xero);
    DB::table('accounting_connections')->where('owner_id', 'org-reconnect')->update(['status' => $status, 'access_token' => null, 'refresh_token' => null]);

    $repo->persist($inFlight->withTokens(new TokenSet('refreshed-access-A', 'refreshed-refresh-A', (new DateTimeImmutable)->modify('+30 minutes'))));

    $row = DB::table('accounting_connections')->where('owner_id', 'org-reconnect')->first();
    expect($row->access_token)->toBeNull()
        ->and($row->refresh_token)->toBeNull()
        ->and($row->status)->toBe($status)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning');
})->with(['revoked', 'disconnected']);

it('still reads an undecryptable token as reconnect when the logger throws', function () {
    $repo = new DatabaseConnectionRepository(
        resolver: app('db'),
        encrypter: app('encrypter'),
        logger: new class extends AbstractLogger
        {
            public function log($level, Stringable|string $message, array $context = []): void
            {
                throw new RuntimeException('Log destination unavailable');
            }
        },
    );

    $repo->save(xeroFor('org-1'));
    DB::table('accounting_connections')->update(['access_token' => 'not-valid-ciphertext']);

    expect($repo->find('org-1', Provider::Xero))->toBeNull();
});

/*
 * persist() and an empty tenant id. save() stores a Connection's tenantId as given, so
 * '' is stored as ''; a host that writes its own rows may leave tenant_id NULL. A
 * refresh carrying '' matches either; one naming a tenant never matches NULL.
 */
function emptyTenantCase(?string $stored_tenant, string $refreshed_tenant): array
{
    $logger = keepingLogger();
    $repo = new DatabaseConnectionRepository(resolver: app('db'), encrypter: app('encrypter'), logger: $logger);

    $repo->save(tenantConnection($stored_tenant ?? '', 'access-old', 'refresh-old', 'org-empty'));
    DB::table('accounting_connections')->where('owner_id', 'org-empty')->update(['tenant_id' => $stored_tenant]);

    $repo->persist(tenantConnection($refreshed_tenant, 'access-new', 'refresh-new', 'org-empty'));

    $row = DB::table('accounting_connections')->where('owner_id', 'org-empty')->first();

    return [Crypt::decryptString($row->access_token), $row->tenant_id, $logger->records];
}

it('persists a refresh with an empty tenant into a row stored with an empty tenant', function () {
    [$access, $tenant, $logs] = emptyTenantCase('', '');

    expect($access)->toBe('access-new')->and($tenant)->toBe('')->and($logs)->toBe([]);
});

it('persists a refresh with an empty tenant into a host-written row with a NULL tenant', function () {
    [$access, $tenant, $logs] = emptyTenantCase(null, '');

    expect($access)->toBe('access-new')->and($tenant)->toBeNull()->and($logs)->toBe([]);
});

it('persists nothing from a refresh naming a tenant into a row with a NULL tenant', function () {
    [$access, $tenant, $logs] = emptyTenantCase(null, 'tenant-A');

    expect($access)->toBe('access-old')
        ->and($tenant)->toBeNull()
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]['level'])->toBe('warning')
        ->and($logs[0]['message'])->not->toContain('will die');
});

it('persists nothing from a refresh with an empty tenant into a row naming a tenant', function () {
    [$access, $tenant, $logs] = emptyTenantCase('tenant-A', '');

    expect($access)->toBe('access-old')
        ->and($tenant)->toBe('tenant-A')
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]['level'])->toBe('warning');
});

it('persists the refresh a disconnect makes when the provider is asked before forget()', function () {
    // The documented order: disconnect at the provider, then forget(). The refresh
    // inside disconnectTenant() lands in a row that is still active.
    $repo = app(ConnectionStore::class);
    app(ConnectionRepository::class)->save(tenantConnection('tenant-1', 'access-old', 'refresh-old', 'org-order'));

    $fake = fakeHttp();
    $fake->queue(200, ['access_token' => 'fresh-token', 'refresh_token' => 'fresh-refresh', 'expires_in' => 1800]);
    $fake->queue(200, [['id' => 'conn-1', 'tenantId' => 'tenant-1']]);
    $fake->queueRaw(204, '');

    $connector = new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        connections: $repo,
    );

    $expired = new Connection(
        provider: Provider::Xero,
        tenantId: 'tenant-1',
        accessToken: 'access-old',
        refreshToken: 'refresh-old',
        expiresAt: (new DateTimeImmutable)->modify('-1 hour'),
        reference: 'org-order',
    );

    expect($connector->disconnectTenant($expired))->toBe(DisconnectOutcome::Removed)
        ->and(app(ConnectionRepository::class)->find('org-order', Provider::Xero)->accessToken)->toBe('fresh-token');

    app(ConnectionRepository::class)->forget('org-order', Provider::Xero);

    expect(app(ConnectionRepository::class)->find('org-order', Provider::Xero))->toBeNull();
});
