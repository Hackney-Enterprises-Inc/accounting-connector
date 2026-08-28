<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * One side of a manual journal entry.
 *
 * A positive amount debits the account, a negative amount credits it. That is
 * Xero's convention on ManualJournalLine and it is the one this package adopts;
 * the QuickBooks connector translates it into the explicit Debit / Credit
 * PostingType that JournalEntry wants.
 */
final readonly class JournalLine
{
    /**
     * @param  array<int, TrackingRef>  $tracking  Xero-only; ignored by QuickBooks.
     */
    public function __construct(
        public string $accountCode,
        public Money $amount,
        public ?string $description = null,
        public ?string $taxCode = null,
        public array $tracking = [],
    ) {}

    public function isDebit(): bool
    {
        return $this->amount->amount >= 0;
    }
}
