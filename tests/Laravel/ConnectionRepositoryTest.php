<?php

declare(strict_types=1);

use Hei\AccountingConnector\Contracts\ConnectionRepository;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\TokenSet;
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
