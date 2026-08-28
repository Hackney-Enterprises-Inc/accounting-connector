<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

/**
 * How settled a posted transaction should be on arrival.
 *
 * Xero honours all three. QuickBooks has no draft state for bills, purchases or
 * invoices; anything posted there is posted for real. The QuickBooks connector
 * therefore ignores this value rather than pretending to honour it, and a host
 * that needs a review step before money moves must hold the document on its own
 * side. See README, "Status is not portable".
 */
enum TransactionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Authorised = 'authorised';

    public function toXero(): string
    {
        return match ($this) {
            self::Draft => 'DRAFT',
            self::Submitted => 'SUBMITTED',
            self::Authorised => 'AUTHORISED',
        };
    }

    /**
     * Manual journals use a different vocabulary in Xero: a posted journal is
     * POSTED, not AUTHORISED, and sending the wrong word is a 400.
     */
    public function toXeroJournal(): string
    {
        return $this === self::Draft ? 'DRAFT' : 'POSTED';
    }
}
