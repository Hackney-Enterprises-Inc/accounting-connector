<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;

/**
 * A manual journal as the provider already holds it.
 *
 * The read counterpart of {@see JournalData}, which is a write payload. It exists for
 * one question: "did a journal I may have posted actually land", asked by a host
 * whose create had an uncertain outcome after the provider stopped remembering the
 * idempotency key. So it carries what identifies and settles that question (the id,
 * the narration that holds the host's marker, the status, the dates) and not the
 * lines, which the host already has in its own intent.
 */
final readonly class ManualJournal
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOIDED = 'VOIDED';

    public const STATUS_DELETED = 'DELETED';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    public function __construct(
        /** The provider's own id: Xero's ManualJournalID. */
        public string $id,
        public string $narration,
        /** Provider-native status, upper case: for Xero DRAFT, POSTED, VOIDED, DELETED or ARCHIVED. */
        public ?string $status = null,
        /** The journal's date, the calendar day at midnight UTC. */
        public ?DateTimeImmutable $date = null,
        /** The provider's last-modified instant for the journal. */
        public ?DateTimeImmutable $updatedAt = null,
        /** The tenant the journal was read from: the connection's tenant at the time of the read. */
        public ?string $tenantId = null,
    ) {}

    /**
     * Whether the journal is on the books: posted, not a draft and not voided or deleted.
     */
    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * Whether it existed and was then taken off the books by someone.
     */
    public function isVoidedOrDeleted(): bool
    {
        return $this->status === self::STATUS_VOIDED || $this->status === self::STATUS_DELETED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'narration' => $this->narration,
            'status' => $this->status,
            'date' => $this->date?->format('Y-m-d'),
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
            'tenant_id' => $this->tenantId,
        ];
    }
}
