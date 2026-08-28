<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Events;

use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\EntityType;

/**
 * An attachment attempt finished, successfully or not.
 *
 * One event for both outcomes because an attachment failure is not an error: the
 * transaction it belongs to already posted. Check AttachmentResult::$uploaded.
 */
final class AttachmentUploaded extends SyncEvent
{
    public function __construct(
        Connection $connection,
        public readonly EntityType $entityType,
        public readonly string $externalId,
        public readonly AttachmentResult $result,
    ) {
        parent::__construct($connection);
    }

    public function name(): string
    {
        return 'attachment.'.($this->result->uploaded ? 'uploaded' : 'skipped');
    }

    public function context(): array
    {
        return parent::context() + [
            'entity_type' => $this->entityType->value,
            'external_id' => $this->externalId,
            'uploaded' => $this->result->uploaded,
            'filename' => $this->result->filename,
            'bytes' => $this->result->bytes,
            'reason' => $this->result->reason,
        ];
    }
}
