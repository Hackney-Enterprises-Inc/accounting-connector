<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * Everything a host is allowed to change on a bank transaction it did not create.
 *
 * Two things, and only these two: how the lines are coded, and which contact the
 * transaction is against when the feed left it with none. Amounts, dates, bank
 * account, reference and reconciliation state came from the bank and are not a
 * host's to edit; a change carries no field that could touch them.
 *
 * The contact is an id, not a name. Resolving a name to an id is a lookup that may
 * find nothing, and the decision about what to do then belongs to the host: a
 * match must never mint a contact in the customer's chart on its own.
 */
final readonly class BankTransactionChange
{
    /**
     * @param  array<int, LineCoding>  $codings
     * @param  string|null  $contactId  The provider's contact id to set, or null to leave the contact alone.
     */
    public function __construct(
        public array $codings = [],
        public ?string $contactId = null,
    ) {}

    /**
     * Code every line the same way, the common single-line case.
     *
     * @param  array<int, TrackingRef>|null  $tracking
     */
    public static function allLines(?string $accountCode, ?array $tracking = null): self
    {
        return new self([LineCoding::forAllLines($accountCode, $tracking)]);
    }

    /**
     * The same change with a contact set as well.
     */
    public function withContact(string $contactId): self
    {
        return new self($this->codings, $contactId);
    }

    /**
     * Whether this would change anything at all.
     *
     * A change that changes nothing is refused before any request is made: a POST
     * that replaces a transaction with itself still costs a call, bumps
     * UpdatedDateUTC and, on Xero, recomputes what it can.
     */
    public function isEmpty(): bool
    {
        if ($this->contactId !== null && $this->contactId !== '') {
            return false;
        }

        foreach ($this->codings as $coding) {
            if (! $coding->isEmpty()) {
                return false;
            }
        }

        return true;
    }
}
