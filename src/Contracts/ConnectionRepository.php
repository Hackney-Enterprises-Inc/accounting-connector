<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;

/**
 * Where connections live.
 *
 * The read side that ConnectionStore's write-on-refresh is a special case of. A
 * shipped Laravel implementation stores these in one `accounting_connections`
 * table, which is what stops each new provider costing another four columns on the
 * host's tenant model.
 *
 * `$owner` is the host's own tenant identifier, and it is what lands in
 * Connection::$reference. The package never learns what an owner actually is; it
 * only ever passes the string back.
 *
 * The table is deliberately not constrained to this package's Provider enum. A host
 * can keep connections for integrations the package has no connector for, such as
 * AccountingPipe's Google Sheets, in the same table and read them itself. Only rows
 * whose provider the package recognises are ever handed back as a Connection.
 */
interface ConnectionRepository
{
    /**
     * The owner's connection to one provider, or null if there is none.
     */
    public function find(string $owner, Provider $provider): ?Connection;

    /**
     * Every connection this owner holds that the package can drive.
     *
     * @return array<int, Connection>
     */
    public function forOwner(string $owner): array;

    /**
     * Create or replace the owner's connection to a provider.
     *
     * The connection's `reference` is the owner. Passing one without a reference is
     * a programming error, because there would be nothing to store it against.
     */
    public function save(Connection $connection): void;

    /**
     * Drop a connection entirely, after revoking it at the provider.
     */
    public function forget(string $owner, Provider $provider): void;

    /**
     * Mark a connection as needing the customer to reconnect.
     *
     * Called on a ConnectionRevoked signal. The row is kept rather than deleted so a
     * host can show "reconnect Xero" against the organization that lost it, and so
     * the entity map's history stays meaningful.
     */
    public function markRevoked(string $owner, Provider $provider, string $reason): void;
}
