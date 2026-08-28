<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Events;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;

/**
 * An entity now exists in the customer's accounting system.
 *
 * Emitted after the provider confirmed the id, before any attachment is attempted.
 * A host listening for this should record the external id immediately: if a later
 * step fails and the job retries, this is what stops a second entity being created.
 */
final class EntityCreated extends SyncEvent
{
    public function __construct(
        Connection $connection,
        public readonly EntityType $entityType,
        public readonly string $externalId,
        public readonly ?string $localId = null,
        public readonly ?string $idempotencyKey = null,
    ) {
        parent::__construct($connection);
    }

    public function name(): string
    {
        return 'entity.created';
    }

    public function context(): array
    {
        return parent::context() + [
            'entity_type' => $this->entityType->value,
            'external_id' => $this->externalId,
            'local_id' => $this->localId,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
