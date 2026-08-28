<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Events;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;
use Throwable;

/**
 * A create was refused.
 *
 * `$retryable` is the useful field: a validation failure needs a human to fix the
 * payload, while a rate limit or a provider outage just needs the job to run again
 * later. Surfacing the first to a bookkeeper and quietly retrying the second is the
 * difference between a useful integration and a noisy one.
 *
 * One deliberate gap: a create that dies in the pre-create token refresh raises
 * (and, on a dead grant, emits ConnectionRevoked) WITHOUT this event, because no
 * create was ever attempted. A sync log keyed on this event alone will not show
 * those documents; listen for ConnectionRevoked as well.
 */
final class EntityCreateFailed extends SyncEvent
{
    public function __construct(
        Connection $connection,
        public readonly EntityType $entityType,
        public readonly string $reason,
        public readonly bool $retryable,
        public readonly ?string $localId = null,
        public readonly ?Throwable $exception = null,
    ) {
        parent::__construct($connection);
    }

    public function name(): string
    {
        return 'entity.create_failed';
    }

    public function context(): array
    {
        return parent::context() + [
            'entity_type' => $this->entityType->value,
            'local_id' => $this->localId,
            'reason' => $this->reason,
            'retryable' => $this->retryable,
            'exception' => $this->exception === null ? null : $this->exception::class,
        ];
    }
}
