<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Events;

use Hei\AccountingConnector\Data\Connection;

/**
 * The reconnect signal.
 *
 * The customer disconnected us at the provider's end, or the refresh token lapsed.
 * Nothing will work on this connection again until somebody re-authorises, so a
 * host listening here should stop dispatching sync jobs for it, mark it as needing
 * attention, and tell the customer. Retrying achieves nothing.
 */
final class ConnectionRevoked extends SyncEvent
{
    public function __construct(
        Connection $connection,
        public readonly string $reason,
    ) {
        parent::__construct($connection);
    }

    public function name(): string
    {
        return 'connection.revoked';
    }

    public function context(): array
    {
        return parent::context() + [
            'reason' => $this->reason,
            'tenant_id' => $this->connection->tenantId,
        ];
    }
}
