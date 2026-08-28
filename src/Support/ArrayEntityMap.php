<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Contracts\EntityMap;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;

/**
 * An entity map held in memory.
 *
 * For tests, and for a one-shot script. Not for an application: it forgets
 * everything at the end of the request, so the next post re-resolves every contact
 * and re-creates anything it cannot find.
 */
final class ArrayEntityMap implements EntityMap
{
    /** @var array<string, string> */
    private array $forward = [];

    /** @var array<string, string> */
    private array $reverse = [];

    public function externalId(Connection $connection, EntityType $type, string $localId): ?string
    {
        return $this->forward[$this->key($connection, $type, $localId)] ?? null;
    }

    public function localId(Connection $connection, EntityType $type, string $externalId): ?string
    {
        return $this->reverse[$this->key($connection, $type, $externalId)] ?? null;
    }

    public function remember(Connection $connection, EntityType $type, string $localId, string $externalId): void
    {
        $this->forget($connection, $type, $localId);

        $this->forward[$this->key($connection, $type, $localId)] = $externalId;
        $this->reverse[$this->key($connection, $type, $externalId)] = $localId;
    }

    public function forget(Connection $connection, EntityType $type, string $localId): void
    {
        $key = $this->key($connection, $type, $localId);
        $externalId = $this->forward[$key] ?? null;

        unset($this->forward[$key]);

        if ($externalId !== null) {
            unset($this->reverse[$this->key($connection, $type, $externalId)]);
        }
    }

    /**
     * Everything recorded, for assertions.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->forward;
    }

    private function key(Connection $connection, EntityType $type, string $id): string
    {
        // The tenant is part of the key, not an afterthought. Two organizations on
        // different Xero tenants will both hold a local id of '1'.
        return $connection->provider->value.'|'.$connection->tenantId.'|'.$type->value.'|'.$id;
    }
}
