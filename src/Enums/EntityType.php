<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\InvoiceData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\PaymentData;

/**
 * The canonical entities a connector can create, both halves of the ledger.
 *
 * Accounts receivable (money in): customer, invoice, payment.
 * Accounts payable (money out): vendor, bill, expense.
 * Journal sits outside both and posts a balanced manual entry.
 *
 * Backing values are persisted in host entity-map tables, so they are part of
 * the public contract and must not change.
 */
enum EntityType: string
{
    case Customer = 'customer';
    case Invoice = 'invoice';
    case Payment = 'payment';

    case Vendor = 'vendor';
    case Bill = 'bill';
    case Expense = 'expense';

    case Journal = 'journal';

    /**
     * Money-in entities. Mittro's invoicing uses this half.
     */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Customer, self::Invoice, self::Payment], true);
    }

    /**
     * Money-out entities. AccountingPipe's receipt and bill capture uses this half.
     */
    public function isPayable(): bool
    {
        return in_array($this, [self::Vendor, self::Bill, self::Expense], true);
    }

    /**
     * Whether this type is a contact rather than a transaction.
     *
     * Contacts are resolved by name and cached in the entity map; transactions
     * are posted with an idempotency key.
     */
    public function isContact(): bool
    {
        return $this === self::Customer || $this === self::Vendor;
    }

    /**
     * The payload class this entity type expects.
     *
     * RawPayload is always accepted as well; see AbstractConnector::assertPayload().
     *
     * @return class-string
     */
    public function payloadClass(): string
    {
        return match ($this) {
            self::Customer, self::Vendor => ContactData::class,
            self::Invoice => InvoiceData::class,
            self::Bill => BillData::class,
            self::Expense => ExpenseData::class,
            self::Payment => PaymentData::class,
            self::Journal => JournalData::class,
        };
    }
}
