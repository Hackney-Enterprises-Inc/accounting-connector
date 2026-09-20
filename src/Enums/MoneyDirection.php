<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

use Hei\AccountingConnector\Data\ExpenseData;

/**
 * Which way money moved.
 *
 * The one fact a host needs before it compares a document to a bank transaction: a
 * receipt evidences money that left an account and can only ever be the same money
 * as a SPEND; a credit note or refund evidences money that came back and can only
 * be a RECEIVE. Comparing across the two is how a refund gets attached to the
 * purchase it reversed.
 *
 * Owned by the package rather than the host so both sides of the seam agree on the
 * same two values: {@see BankTransactionType::direction()} derives one from a
 * provider's type, and {@see ExpenseData::$direction}
 * carries one onto a create.
 */
enum MoneyDirection: string
{
    case Out = 'out';
    case In = 'in';

    /**
     * The plain bank transaction type a create in this direction posts as.
     */
    public function bankTransactionType(): BankTransactionType
    {
        return match ($this) {
            self::Out => BankTransactionType::Spend,
            self::In => BankTransactionType::Receive,
        };
    }

    public function isOut(): bool
    {
        return $this === self::Out;
    }

    public function isIn(): bool
    {
        return $this === self::In;
    }
}
