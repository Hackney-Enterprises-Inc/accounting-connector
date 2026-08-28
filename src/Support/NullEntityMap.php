<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Contracts\EntityMap;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;

/**
 * An entity map that remembers nothing.
 *
 * The default, so a connector can be constructed without one. Everything still
 * works: contacts are resolved by querying the provider every time, and duplicate
 * posts are prevented by idempotency keys rather than by local memory. It is just
 * slower, by one extra round trip per contact per post.
 */
final class NullEntityMap implements EntityMap
{
    public function externalId(Connection $connection, EntityType $type, string $localId): ?string
    {
        return null;
    }

    public function localId(Connection $connection, EntityType $type, string $externalId): ?string
    {
        return null;
    }

    public function remember(Connection $connection, EntityType $type, string $localId, string $externalId): void
    {
        // Intentionally nothing.
    }

    public function forget(Connection $connection, EntityType $type, string $localId): void
    {
        // Intentionally nothing.
    }
}
