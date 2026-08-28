<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Data\Connection;

/**
 * A lookup store held in memory. For tests, and for a one-shot script.
 *
 * Mirrors ArrayEntityMap: useful for asserting that a lookup was written, and for
 * seeding a stored list to prove the outage fallback works, but it forgets
 * everything at the end of the request so it is not an application's answer.
 */
final class ArrayLookupStore implements LookupStore
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $stored = [];

    /** @var array<int, string> */
    public array $writes = [];

    public function get(Connection $connection, string $key): ?array
    {
        return $this->stored[$this->key($connection, $key)] ?? null;
    }

    public function put(Connection $connection, string $key, array $records): void
    {
        $this->stored[$this->key($connection, $key)] = $records;
        $this->writes[] = $key;
    }

    /**
     * Seed a stored list without going through a connector.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    public function seed(Connection $connection, string $key, array $records): void
    {
        $this->stored[$this->key($connection, $key)] = $records;
    }

    private function key(Connection $connection, string $key): string
    {
        // Scoped per tenant, for the same reason the entity map is.
        return $connection->provider->value.'|'.$connection->tenantId.'|'.$key;
    }
}
