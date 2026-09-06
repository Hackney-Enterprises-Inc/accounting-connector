<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * A change to how one line, or every line, is coded.
 *
 * The narrowest useful write against a transaction that already exists: it names an
 * account and tracking and nothing else, so the amounts, date, contact and
 * reconciliation state a bank feed put there cannot be disturbed by a host that only
 * wanted to say which expense account the money belongs to.
 *
 * Null means "leave this alone" on both fields, which is what makes coding tracking
 * without touching the account, or the other way round, expressible. An empty
 * tracking array is a different instruction: it clears the tracking that is there.
 */
final readonly class LineCoding
{
    /**
     * @param  array<int, TrackingRef>|null  $tracking
     */
    private function __construct(
        /** The line to change, or null for every line on the transaction. */
        public ?string $lineItemId,
        public ?string $accountCode,
        public ?array $tracking,
    ) {}

    /**
     * Code every line on the transaction the same way.
     *
     * The common case: a receipt carries one account code and the bank feed created
     * a single-line transaction for it.
     *
     * @param  array<int, TrackingRef>|null  $tracking
     */
    public static function forAllLines(?string $accountCode, ?array $tracking = null): self
    {
        return new self(null, $accountCode, $tracking);
    }

    /**
     * Code one named line, leaving the others as they are.
     *
     * @param  array<int, TrackingRef>|null  $tracking
     */
    public static function forLine(string $lineItemId, ?string $accountCode, ?array $tracking = null): self
    {
        return new self($lineItemId, $accountCode, $tracking);
    }

    /**
     * Whether this coding applies to the given line.
     */
    public function appliesTo(?string $lineItemId): bool
    {
        return $this->lineItemId === null || $this->lineItemId === $lineItemId;
    }

    /**
     * Whether this would change anything at all.
     */
    public function isEmpty(): bool
    {
        return $this->accountCode === null && $this->tracking === null;
    }
}
