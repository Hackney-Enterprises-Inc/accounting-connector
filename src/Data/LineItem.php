<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * One line on a transaction.
 *
 * A line is expressed either as quantity times unit amount, or as a flat line
 * amount. Both are needed: ordinary bills carry quantities, while an allocation
 * split carries only its share of a total and inventing a quantity for it would
 * round the split wrong.
 *
 * `$accountCode` is the destination account. Xero addresses accounts by their
 * short code ('400'); QuickBooks addresses them by an opaque per-company id
 * ('63'). The value is passed through untouched, so it must match the provider
 * the connection points at. Use ConnectorInterface::chartOfAccounts() to fill a
 * settings dropdown rather than hardcoding either form.
 */
final readonly class LineItem
{
    /**
     * @param  array<int, TrackingRef>  $tracking  Xero-only; ignored by QuickBooks.
     */
    public function __construct(
        public string $description,
        public ?Money $unitAmount = null,
        public float $quantity = 1.0,
        public ?Money $lineAmount = null,
        public ?string $accountCode = null,
        public ?string $taxCode = null,
        public array $tracking = [],
    ) {
        if ($this->unitAmount === null && $this->lineAmount === null) {
            throw new InvalidPayloadException(
                "Line item '{$this->description}' needs either a unitAmount or a lineAmount."
            );
        }
    }

    /**
     * The line's total, whether it was given directly or implied by quantity.
     */
    public function total(): Money
    {
        if ($this->lineAmount !== null) {
            return $this->lineAmount;
        }

        /** @var Money $unit */
        $unit = $this->unitAmount;

        return $unit->times($this->quantity);
    }

    /**
     * Whether this line was expressed as a flat amount rather than quantity times price.
     *
     * Xero needs to know: a flat line posts as LineAmount, a priced line posts as
     * Quantity plus UnitAmount, and sending both makes Xero recompute the total.
     */
    public function isFlatAmount(): bool
    {
        return $this->lineAmount !== null;
    }

    public function hasTracking(): bool
    {
        return $this->tracking !== [];
    }
}
