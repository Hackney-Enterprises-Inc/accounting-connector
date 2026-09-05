<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Laravel;

use Hei\AccountingConnector\Contracts\EntityMap;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * The entity map over a database table.
 *
 * Query builder rather than Eloquent, deliberately: the package ships no models, so
 * a host is free to put a model over the same table without inheriting one.
 *
 * The migration is publishable rather than automatic, because each application owns
 * its own schema and its own migration ordering.
 *
 *     php artisan vendor:publish --tag=accounting-connector-migrations
 */
final class DatabaseEntityMap implements EntityMap
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly string $table = 'accounting_entity_map',
        private readonly ?string $connectionName = null,
    ) {}

    public function externalId(Connection $connection, EntityType $type, string $localId): ?string
    {
        $value = $this->scoped($connection, $type)
            ->where('local_id', $localId)
            ->value('external_id');

        return $value === null ? null : (string) $value;
    }

    public function localId(Connection $connection, EntityType $type, string $externalId): ?string
    {
        $value = $this->scoped($connection, $type)
            ->where('external_id', $externalId)
            ->value('local_id');

        return $value === null ? null : (string) $value;
    }

    public function remember(Connection $connection, EntityType $type, string $localId, string $externalId): void
    {
        $now = $this->query()->getConnection()->raw('CURRENT_TIMESTAMP');
        $owner = $connection->reference;

        // upsert rather than insert: a re-sync of the same document must replace the
        // mapping, not collide with the unique index and blow up a queue job.
        $this->query()->upsert(
            [[
                'provider' => $connection->provider->value,
                'tenant_id' => $connection->tenantId,
                'entity_type' => $type->value,
                'local_id' => $localId,
                'external_id' => $externalId,
                // Audit only, never read back here: the host's owner of the
                // connection that wrote the row, so a mapping can be traced to the
                // tenant it was posted for. A connection carrying no reference
                // stores null rather than an empty string, so unknown reads as
                // unknown. Refreshed on every upsert, because a row naming an owner
                // that no longer writes it is worse than a row naming none.
                'owner_id' => ($owner === null || $owner === '') ? null : $owner,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['provider', 'tenant_id', 'entity_type', 'local_id'],
            ['external_id', 'owner_id', 'updated_at'],
        );
    }

    public function forget(Connection $connection, EntityType $type, string $localId): void
    {
        $this->scoped($connection, $type)->where('local_id', $localId)->delete();
    }

    /**
     * Every lookup is scoped to the tenant, not just the provider.
     *
     * Two organizations on different Xero tenants will both hold a local id of '1'.
     * Dropping the tenant from this clause would hand one customer's external id
     * back for the other's local id, and post their money into a stranger's ledger.
     */
    private function scoped(Connection $connection, EntityType $type): Builder
    {
        return $this->query()
            ->where('provider', $connection->provider->value)
            ->where('tenant_id', $connection->tenantId)
            ->where('entity_type', $type->value);
    }

    private function query(): Builder
    {
        return $this->resolver->connection($this->connectionName)->table($this->table);
    }
}
