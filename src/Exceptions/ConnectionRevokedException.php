<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;

/**
 * The connection is dead and only a human can revive it.
 *
 * Raised when the refresh token itself is rejected: the customer disconnected the
 * app at the provider's end, the refresh token expired unused (60 days for Xero,
 * 100 days for Intuit), or a rotated Intuit refresh token was never persisted.
 *
 * A host catching this should stop retrying, mark the connection as needing
 * reconnection, and tell somebody. Retrying is guaranteed to fail.
 */
final class ConnectionRevokedException extends AccountingConnectorException
{
    public function __construct(
        string $message,
        public readonly Connection $connection,
        ?string $providerMessage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $connection->provider, $providerMessage, 0, $previous);
    }

    public static function for(Connection $connection, ?string $providerMessage = null): self
    {
        return new self(
            sprintf(
                '%s connection %s was revoked or its refresh token expired. The customer must reconnect.',
                $connection->provider->label(),
                $connection->reference ?? $connection->tenantId,
            ),
            $connection,
            $providerMessage,
        );
    }
}
