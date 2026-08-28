<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Data\Connection;

/**
 * Stores nothing. The default.
 *
 * Everything still works: lookups are cached in PSR-16 and re-fetched when the cache
 * misses. What is lost is durability across a cache flush and the ability to serve a
 * stale list during a provider outage. Bind a real LookupStore if either matters,
 * which for a customer-facing settings page it does.
 */
final class NullLookupStore implements LookupStore
{
    public function get(Connection $connection, string $key): ?array
    {
        return null;
    }

    public function put(Connection $connection, string $key, array $records): void
    {
        // Intentionally nothing.
    }
}
