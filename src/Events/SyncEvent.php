<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Events;

use DateTimeImmutable;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;

/**
 * Base for everything this package announces.
 *
 * These are the sync-log contract. The package never writes a log table; it emits
 * these through a PSR-14 dispatcher and the host records them however it likes.
 * AccountingPipe writes them to its sync log; Mittro writes them to its audit trail.
 *
 * Nothing here carries a token. `context()` is written to be safe to serialise
 * straight into a database column or a log line.
 */
abstract class SyncEvent
{
    public readonly DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly Connection $connection,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable;
    }

    public function provider(): Provider
    {
        return $this->connection->provider;
    }

    /**
     * A short machine-readable name for this event, stable across versions.
     */
    abstract public function name(): string;

    /**
     * Everything worth persisting, with no credentials in it.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'event' => $this->name(),
            'provider' => $this->connection->provider->value,
            'connection' => $this->connection->reference,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
