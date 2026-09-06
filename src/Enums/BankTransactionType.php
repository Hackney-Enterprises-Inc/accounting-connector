<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

/**
 * What kind of bank transaction a provider is holding.
 *
 * Only the money-out half is actionable for a host matching receipts: a receipt
 * evidences money that left an account, so SPEND is the type a matcher looks at and
 * everything else is here so an unexpected value round-trips rather than being
 * silently reshaped into a spend.
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
     * type must not cost the other ninety-nine rows on the page. The caller sees a
     * null type and can skip that row.
     */
    public static function tryFromXero(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper($value));
    }

    /**
     * Whether this type represents money leaving the account.
     */
    public function isSpend(): bool
    {
        return str_starts_with($this->value, 'SPEND');
    }
}
