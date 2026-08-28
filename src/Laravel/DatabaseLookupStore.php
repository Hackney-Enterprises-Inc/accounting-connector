<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Laravel;

use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Data\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * Cached provider reference data, one row per lookup key.
 *
 * One row per key rather than one JSON column holding all of them, so each refresh
 * is an independent upsert. A single blob would make every refresh a
 * read-modify-write, and two concurrent refreshes would silently clobber one another.
 *
 * Rows are keyed by owner and provider rather than by a connection's surrogate id,
 * so a reconnect that replaces the connection row does not orphan the cached lists.
 */
final class DatabaseLookupStore implements LookupStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly string $table = 'accounting_connection_lookups',
        private readonly ?string $connectionName = null,
    ) {}

    public function get(Connection $connection, string $key): ?array
    {
        $owner = $connection->reference;

        if ($owner === null || $owner === '') {
            return null;
        }

        $payload = $this->query()
            ->where('owner_id', $owner)
            ->where('provider', $connection->provider->value)
            ->where('lookup_key', $key)
            ->value('payload');

        if (! is_string($payload)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(Connection $connection, string $key, array $records): void
    {
        $owner = $connection->reference;

        if ($owner === null || $owner === '') {
            // Nothing to key the row on. The connector still works; it just re-fetches.
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->query()->upsert(
            [[
                'owner_id' => $owner,
                'provider' => $connection->provider->value,
                'lookup_key' => $key,
                'payload' => json_encode($records, JSON_THROW_ON_ERROR),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['owner_id', 'provider', 'lookup_key'],
            ['payload', 'synced_at', 'updated_at'],
        );
    }

    /**
     * When a lookup was last fetched from the provider.
     *
     * Backs a "last synced" line on an integrations page, which is the replacement
     * for AccountingPipe's `xero_accounts_synced_at` column.
     */
    public function syncedAt(Connection $connection, string $key): ?string
    {
        $owner = $connection->reference;

        if ($owner === null || $owner === '') {
            return null;
        }

        $value = $this->query()
            ->where('owner_id', $owner)
            ->where('provider', $connection->provider->value)
            ->where('lookup_key', $key)
            ->value('synced_at');

        return $value === null ? null : (string) $value;
    }

    /**
     * Drop every cached list for a connection, on disconnect.
     */
    public function flush(Connection $connection): void
    {
        $owner = $connection->reference;

        if ($owner === null || $owner === '') {
            return;
        }

        $this->query()
            ->where('owner_id', $owner)
            ->where('provider', $connection->provider->value)
            ->delete();
    }

    private function query(): Builder
    {
        return $this->resolver->connection($this->connectionName)->table($this->table);
    }
}
