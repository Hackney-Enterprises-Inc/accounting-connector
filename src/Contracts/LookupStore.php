<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;

/**
 * Durable storage for lookup lists, behind the cache.
 *
 * This exists because a PSR-16 cache alone is not good enough for this data, and
 * AccountingPipe already learned why. Its chart of accounts, bank accounts and
 * tracking categories live in real columns on the organization, not just in the
 * cache, for two reasons worth preserving:
 *
 *  1. They survive a cache flush. Otherwise every deploy that clears the cache makes
 *     the next settings page load hit Xero three times, per organization, against a
 *     60-calls-per-minute ceiling shared with every other app the customer uses.
 *  2. They are served when the provider is unreachable. A Xero outage should make a
 *     settings page slightly stale, not empty. An empty account dropdown reads to a
 *     customer as "my chart of accounts is gone".
 *
 * The package ships no implementation beyond a null one, because the host owns its
 * schema. AccountingPipe implements this over the `xero_*` columns it already has,
 * which means porting needs no migration.
 */
interface LookupStore
{
    /**
     * The stored list, or null if nothing has been stored yet.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function get(Connection $connection, string $key): ?array;

    /**
     * Persist a freshly fetched list.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    public function put(Connection $connection, string $key, array $records): void;
}
