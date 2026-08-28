<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Data\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Throws refreshed tokens away, loudly.
 *
 * The default, so a connector is constructible without one, but it warns every time
 * it discards a refresh because in an application that is a bug with a delayed fuse:
 * Intuit rotates its refresh token on each use, so the second refresh presents a
 * token Intuit already retired and the connection dies days later with no obvious
 * cause. Bind a real ConnectionStore before shipping.
 */
final class NullConnectionStore implements ConnectionStore
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function persist(Connection $connection): void
    {
        $this->logger->warning(
            'Refreshed accounting tokens were discarded: no ConnectionStore is bound. '
            .'QuickBooks rotates its refresh token on every refresh, so this connection will stop working.',
            [
                'provider' => $connection->provider->value,
                'connection' => $connection->reference,
            ],
        );
    }
}
