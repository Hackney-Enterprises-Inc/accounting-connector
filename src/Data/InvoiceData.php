<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\LineAmountType;
use Hei\AccountingConnector\Enums\TransactionStatus;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * An invoice raised against a customer: money in, not yet received.
 *
 * Posts as a Xero ACCREC Invoice or a QuickBooks Invoice.
 *
 * Note the account on each line means the opposite of what it does on a bill: this
 * is an income account, not an expense account. Wiring an expense account into an
 * invoice line posts a negative expense, which reconciles but reads as nonsense.
 */
final readonly class InvoiceData implements EntityPayload
{
    /**
     * @param  array<int, LineItem>  $lines
     */
    public function __construct(
        public ContactData|string $customer,
        public DateTimeImmutable $date,
        public array $lines,
        public string $currency = 'USD',
        public ?DateTimeImmutable $dueDate = null,
        /** Our invoice number as shown to the customer. */
        public ?string $documentNumber = null,
        public ?string $reference = null,
        public TransactionStatus $status = TransactionStatus::Authorised,
        public LineAmountType $lineAmountType = LineAmountType::Exclusive,
        public ?string $localId = null,
    ) {
        if ($this->lines === []) {
            throw new InvalidPayloadException('An invoice needs at least one line item.');
        }

        if (strlen($this->currency) !== 3) {
            throw new InvalidPayloadException("Currency must be a 3-letter ISO code, got '{$this->currency}'.");
        }
    }

    public function entityType(): EntityType
    {
        return EntityType::Invoice;
    }

    public function localId(): ?string
    {
        return $this->localId;
    }

    public function customerContact(): ContactData
    {
        return $this->customer instanceof ContactData
            ? $this->customer
            : ContactData::customer($this->customer);
    }

    public function total(): Money
    {
        return array_reduce(
            $this->lines,
            fn (Money $carry, LineItem $line): Money => $carry->plus($line->total()),
            Money::zero(),
        );
    }
}
