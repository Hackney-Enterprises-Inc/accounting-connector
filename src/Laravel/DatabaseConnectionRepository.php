<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Laravel;

use DateTimeImmutable;
use Hei\AccountingConnector\Contracts\ConnectionRepository;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\AccountingConnectorException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Connections in one table, with tokens encrypted by the application key.
 *
 * Satisfies both contracts on purpose. ConnectionRepository is the read and write
 * surface a host uses; ConnectionStore is the narrow write-on-refresh seam the
 * connectors call. Binding one object to both is what makes rotated QuickBooks
 * refresh tokens persist without the host writing any glue, which is the single
 * most consequential thing to get wrong in this package.
 *
 * The framework-free core still never encrypts anything. This lives in the Laravel
 * bridge and uses the application's own encrypter, so the key never leaves the host.
 *
 * Encryption is deliberately unserialized (`encrypt($value, false)`), which is
 * byte-for-byte what Eloquent's own `encrypted` cast produces. That means a host can
 * put a plain `'access_token' => 'encrypted'` cast on its own model over the same
 * table and both agree. Serializing here instead would have made the two silently
 * incompatible, which is the sort of thing found only once a token fails to decrypt.
 *
 * Query builder rather than Eloquent, so a host is free to put its own model over
 * the same table without inheriting one from a package.
 */
final class DatabaseConnectionRepository implements ConnectionRepository, ConnectionStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly Encrypter $encrypter,
        private readonly string $table = 'accounting_connections',
        private readonly ?string $connectionName = null,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function find(string $owner, Provider $provider): ?Connection
    {
        $row = $this->query()
            ->where('owner_id', $owner)
            ->where('provider', $provider->value)
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    public function forOwner(string $owner): array
    {
        $connections = [];

        foreach ($this->query()->where('owner_id', $owner)->get() as $row) {
            $connection = $this->hydrate((array) $row);

            // Rows for providers this package has no connector for are skipped, not
            // an error. A host may legitimately keep other integrations here.
            if ($connection !== null) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    public function save(Connection $connection): void
    {
        $owner = $connection->reference;

        if ($owner === null || $owner === '') {
            throw new AccountingConnectorException(
                'Cannot store a connection with no reference: the reference is the owner it belongs to.',
                $connection->provider,
            );
        }

        $now = $this->now();

        $this->query()->upsert(
            [[
                'owner_id' => $owner,
                'provider' => $connection->provider->value,
                'tenant_id' => $connection->tenantId,
                'access_token' => $this->encrypter->encrypt($connection->accessToken, false),
                'refresh_token' => $connection->refreshToken === null
                    ? null
                    : $this->encrypter->encrypt($connection->refreshToken, false),
                'expires_at' => $connection->expiresAt?->format('Y-m-d H:i:s'),
                'refresh_token_expires_at' => $connection->refreshTokenExpiresAt?->format('Y-m-d H:i:s'),
                'settings' => json_encode($connection->settings, JSON_THROW_ON_ERROR),
                // Saving a connection is how a reconnect completes, so it clears any
                // previous revocation rather than leaving a working connection
                // flagged as broken.
                'status' => 'active',
                'revoked_reason' => null,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['owner_id', 'provider'],
            [
                'tenant_id', 'access_token', 'refresh_token', 'expires_at',
                'refresh_token_expires_at', 'settings', 'status', 'revoked_reason',
                'revoked_at', 'updated_at',
            ],
        );
    }

    /**
     * Persist tokens the connector just refreshed.
     *
     * Deliberately narrower than save(): it touches only the token columns, so a
     * refresh racing a settings update cannot roll the settings back.
     */
    public function persist(Connection $connection): void
    {
        $owner = $connection->reference;

        if ($owner === null || $owner === '') {
            $this->logger->warning(
                'Refreshed accounting tokens could not be stored: the connection carries no reference.',
                ['provider' => $connection->provider->value],
            );

            return;
        }

        $updated = $this->query()
            ->where('owner_id', $owner)
            ->where('provider', $connection->provider->value)
            ->update([
                'access_token' => $this->encrypter->encrypt($connection->accessToken, false),
                'refresh_token' => $connection->refreshToken === null
                    ? null
                    : $this->encrypter->encrypt($connection->refreshToken, false),
                'expires_at' => $connection->expiresAt?->format('Y-m-d H:i:s'),
                'refresh_token_expires_at' => $connection->refreshTokenExpiresAt?->format('Y-m-d H:i:s'),
                'updated_at' => $this->now(),
            ]);

        // Zero rows means the tokens went nowhere, and Intuit has already retired
        // the refresh token they replaced. Silence here is a connection that dies
        // days later with no obvious cause, so it is loud instead. (A real refresh
        // always changes the access token, so an unchanged-values zero cannot occur.)
        if ($updated === 0) {
            $this->logger->error(
                'Refreshed accounting tokens matched no stored connection row and were NOT persisted. '
                .'Save the connection before refreshing it, or this connection will die at the next refresh.',
                [
                    'provider' => $connection->provider->value,
                    'connection' => $owner,
                ],
            );
        }
    }

    public function forget(string $owner, Provider $provider): void
    {
        $this->query()
            ->where('owner_id', $owner)
            ->where('provider', $provider->value)
            ->delete();
    }

    public function markRevoked(string $owner, Provider $provider, string $reason): void
    {
        $this->query()
            ->where('owner_id', $owner)
            ->where('provider', $provider->value)
            ->update([
                'status' => 'revoked',
                'revoked_reason' => $reason,
                'revoked_at' => $this->now(),
                // The tokens are worthless now, and keeping ciphertext around that
                // can never be used again is a liability with no upside.
                'access_token' => null,
                'refresh_token' => null,
                'updated_at' => $this->now(),
            ]);
    }

    /**
     * Whether the owner holds a usable connection to this provider.
     *
     * Connected means a stored, non-revoked row with a refresh token. A lapsed
     * access token does not read as disconnected, because it is renewed on use.
     */
    public function isConnected(string $owner, Provider $provider): bool
    {
        $connection = $this->find($owner, $provider);

        return $connection !== null && $connection->isRefreshable();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hydrate(array $row): ?Connection
    {
        $provider = Provider::tryFrom((string) ($row['provider'] ?? ''));

        if ($provider === null || ($row['status'] ?? 'active') === 'revoked') {
            return null;
        }

        $accessToken = $this->decrypt($row['access_token'] ?? null);

        if ($accessToken === null) {
            return null;
        }

        $settings = json_decode((string) ($row['settings'] ?? '{}'), true);

        return new Connection(
            provider: $provider,
            tenantId: (string) ($row['tenant_id'] ?? ''),
            accessToken: $accessToken,
            refreshToken: $this->decrypt($row['refresh_token'] ?? null),
            expiresAt: $this->toDate($row['expires_at'] ?? null),
            refreshTokenExpiresAt: $this->toDate($row['refresh_token_expires_at'] ?? null),
            settings: is_array($settings) ? $settings : [],
            reference: (string) ($row['owner_id'] ?? ''),
        );
    }

    /**
     * Decrypt a stored token, treating an unreadable one as absent.
     *
     * An APP_KEY rotation makes every stored token undecryptable. Returning null
     * rather than throwing turns that into "this tenant must reconnect", which is
     * the truth and is recoverable, instead of a fatal error on every queue job.
     */
    private function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $plain = $this->encrypter->decrypt($value, false);

            return is_string($plain) ? $plain : null;
        } catch (DecryptException $e) {
            $this->logger->error(
                'A stored accounting token could not be decrypted. The application key may have changed; this connection needs reconnecting.',
                ['error' => $e->getMessage()],
            );

            return null;
        }
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function query(): Builder
    {
        return $this->resolver->connection($this->connectionName)->table($this->table);
    }
}
