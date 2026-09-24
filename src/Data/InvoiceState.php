<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;

/**
 * An invoice or bill as the provider holds it right now: enough to decide whether
 * it can still be changed, voided, or has money applied to it.
 *
 * Deliberately not the full document. A host that posted the bill already has the
 * lines; what it cannot know without asking is the status Xero moved it to since
 * (a payment made it PAID, a bookkeeper voided it by hand) and whether anything is
 * allocated against it. Those are the two facts every write to an existing invoice
 * has to check first, so they are what this carries.
 *
 * `$status` is the provider's own word, upper-cased: Xero's DRAFT, SUBMITTED,
 * AUTHORISED, PAID, VOIDED or DELETED. `$hasPayments` is true when any payment,
 * credit note, prepayment or overpayment is applied, or an amount has been paid
 * or credited; it is what a void has to be clear of.
 */
final readonly class InvoiceState
{
    public function __construct(
        public string $id,
        public ?string $status,
        public ?string $type = null,
        public ?string $invoiceNumber = null,
        public ?string $reference = null,
        public ?DateTimeImmutable $date = null,
        public ?Money $total = null,
        public ?Money $amountDue = null,
        public ?Money $amountPaid = null,
        public ?Money $amountCredited = null,
        public ?string $currency = null,
        public ?string $contactId = null,
        public ?string $contactName = null,
        public bool $hasPayments = false,
        public bool $hasAttachments = false,
        public ?DateTimeImmutable $updatedDateUtc = null,
    ) {}

    /**
     * Voided or deleted: the provider no longer counts it, whichever word it used.
     * An invoice the provider no longer returns at all is reported this way too.
     */
    public function isVoided(): bool
    {
        return in_array($this->normalisedStatus(), ['VOIDED', 'DELETED'], true);
    }

    public function isPaid(): bool
    {
        return $this->normalisedStatus() === 'PAID';
    }

    /**
     * Whether a replacing write would be accepted at all. Xero refuses to modify a
     * PAID, VOIDED or DELETED invoice ("Invoice not of valid status for
     * modification"); the three live states take an update.
     */
    public function isModifiable(): bool
    {
        return in_array($this->normalisedStatus(), ['DRAFT', 'SUBMITTED', 'AUTHORISED'], true);
    }

    /**
     * The status a void has to ask for. Xero deletes what was never approved and
     * voids what was: a DRAFT or SUBMITTED invoice takes DELETED, an AUTHORISED one
     * takes VOIDED, and sending the other word is refused.
     */
    public function voidTarget(): string
    {
        return in_array($this->normalisedStatus(), ['DRAFT', 'SUBMITTED'], true) ? 'DELETED' : 'VOIDED';
    }

    private function normalisedStatus(): ?string
    {
        return $this->status === null ? null : strtoupper($this->status);
    }
}
