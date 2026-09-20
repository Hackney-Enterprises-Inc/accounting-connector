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
     * The account codes a transaction carries once this change has been applied
     * to the codes it carried before, line by line.
     *
     * @param  array<string, string|null>  $before  Line id (or `#index`) to account code.
     * @return array<string, string|null>
     */
    public function codesAfter(array $before): array
    {
        $after = $before;

        foreach ($before as $line => $code) {
            // An `#index` key is a line with no id, which only an all-lines coding reaches.
            $lineId = str_starts_with((string) $line, '#') ? null : (string) $line;

            foreach ($this->codings as $coding) {
                if ($coding->appliesTo($lineId) && $coding->accountCode !== null) {
                    $after[$line] = $coding->accountCode;
                }
            }
        }

        return $after;
    }

    /**
     * Whether a transaction already carries everything this change would set:
     * every targeted line's account code and tracking, and the contact when one is
     * named. What the change does not touch is not looked at here.
     */
    public function isSatisfiedBy(BankTransactionData $transaction): bool
    {
        if ($this->contactId !== null && $this->contactId !== '' && $transaction->contactId !== $this->contactId) {
            return false;
        }

        foreach ($transaction->lines as $line) {
            foreach ($this->codings as $coding) {
                if (! $coding->appliesTo($line->lineItemId)) {
                    continue;
                }

                if ($coding->accountCode !== null && $line->accountCode !== $coding->accountCode) {
                    return false;
                }

                if ($coding->tracking !== null && ! self::sameTracking($line->tracking, $coding->tracking)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int, TrackingRef>  $a
     * @param  array<int, TrackingRef>  $b
     */
    private static function sameTracking(array $a, array $b): bool
    {
        $key = static fn (TrackingRef $ref): string => $ref->categoryId.'|'.$ref->optionId;
        $left = array_map($key, $a);
        $right = array_map($key, $b);
        sort($left);
        sort($right);

        return $left === $right;
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
