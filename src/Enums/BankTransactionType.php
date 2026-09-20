<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

/**
 * What kind of bank transaction a provider is holding.
 *
 * Only the plain SPEND and RECEIVE halves are actionable for a host matching
 * documents: a receipt evidences money that left an account and a credit note
 * money that came back. The transfer, prepayment and overpayment variants are here
 * so an unexpected value round-trips rather than being silently reshaped into a
 * spend, and so a mirror can be honest about what it pulled; none of them is ever
 * a match candidate. A transfer leg belongs to a pair between the customer's own
 * accounts and recoding one breaks the pair; a prepayment or overpayment line is a
 * control account waiting to be allocated to an invoice or bill.
 *
 * Backing values are the strings Xero itself uses, because they are what a host
 * persists in a mirror table and what a `where` clause has to send back.
 */
enum BankTransactionType: string
{
    case Spend = 'SPEND';
    case Receive = 'RECEIVE';
    case SpendOverpayment = 'SPEND-OVERPAYMENT';
    case ReceiveOverpayment = 'RECEIVE-OVERPAYMENT';
    case SpendPrepayment = 'SPEND-PREPAYMENT';
    case ReceivePrepayment = 'RECEIVE-PREPAYMENT';
    case SpendTransfer = 'SPEND-TRANSFER';
    case ReceiveTransfer = 'RECEIVE-TRANSFER';

    /**
     * A type we do not have a case for, without failing the whole page.
     *
     * A list call returns whatever the customer's books hold, and one unrecognised
     * type must not cost the other rows on the page. The caller sees a null type
     * and can skip that row.
     */
    public static function tryFromXero(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper($value));
    }

    /**
     * Whether this type represents money leaving the account, of any variant.
     *
     * Not the question a matcher asks; see {@see self::isMatchable()}. This is
     * true for a transfer or prepayment leg too, which is exactly why a candidate
     * filter must not be built on it.
     */
    public function isSpend(): bool
    {
        return str_starts_with($this->value, 'SPEND');
    }

    /**
     * Whether a document can ever be matched to, attached to or recoded on this.
     *
     * Only the two plain types. Everything else round-trips through a mirror and
     * stops there.
     */
    public function isMatchable(): bool
    {
        return $this === self::Spend || $this === self::Receive;
    }

    /**
     * Which way the money moved, for the two types a document can match.
     *
     * Null for every other variant, deliberately: a host that stores this on its
     * mirror row gets a column that is null for exactly the rows it must never
     * offer as candidates, so one `where direction = ?` does the whitelisting.
     */
    public function direction(): ?MoneyDirection
    {
        return match ($this) {
            self::Spend => MoneyDirection::Out,
            self::Receive => MoneyDirection::In,
            default => null,
        };
    }
}
