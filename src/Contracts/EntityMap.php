<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;

/**
 * The local-id to external-id correspondence, per connection and entity type.
 *
 * The package never owns a database. This is the seam a host implements over
 * whatever table it already has. Two ready-made implementations ship: an
 * in-memory one for tests (ArrayEntityMap) and a query-builder-backed one for
 * Laravel apps (Laravel\DatabaseEntityMap).
 *
 * Implementations MUST scope every lookup to the connection. Two organizations
 * connected to different Xero tenants will both hold a local id of '1', and
 * returning one tenant's external id for the other's local id posts a customer's
 * money into a stranger's ledger.
 */
interface EntityMap
{
    /**
     * The external id previously recorded for this local id, if any.
     */
    public function externalId(Connection $connection, EntityType $type, string $localId): ?string;

    /**
     * The local id that maps to this external id, if any.
     *
     * Used when a provider webhook arrives carrying only the external id.
     */
    public function localId(Connection $connection, EntityType $type, string $externalId): ?string;

    /**
     * Record the correspondence, replacing any existing entry for the local id.
     */
    public function remember(Connection $connection, EntityType $type, string $localId, string $externalId): void;

    /**
     * Drop the correspondence, so the next post creates a fresh entity.
     *
     * Called when a provider reports the external entity as deleted or voided.
     */
    public function forget(Connection $connection, EntityType $type, string $localId): void;
}
