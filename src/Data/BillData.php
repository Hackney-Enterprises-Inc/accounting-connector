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
 * A bill owed to a vendor: money out, not yet paid.
 *
 * Posts as a Xero ACCPAY Invoice or a QuickBooks Bill.
 */
final readonly class BillData implements EntityPayload
{
    /**
     * @param  array<int, LineItem>  $lines
     */
    public function __construct(
        public ContactData|string $vendor,
        public DateTimeImmutable $date,
        public array $lines,
        public string $currency = 'USD',
        public ?DateTimeImmutable $dueDate = null,
        /** The vendor's own invoice number, shown on the bill. */
        public ?string $documentNumber = null,
        /** Free text; both providers surface it in their UI. */
        public ?string $reference = null,
        public TransactionStatus $status = TransactionStatus::Draft,
        public LineAmountType $lineAmountType = LineAmountType::Exclusive,
        /** The host's own id, used as the entity-map key and to build idempotency keys. */
        public ?string $localId = null,
    ) {
        if ($this->lines === []) {
            throw new InvalidPayloadException('A bill needs at least one line item.');
        }

        if (strlen($this->currency) !== 3) {
            throw new InvalidPayloadException("Currency must be a 3-letter ISO code, got '{$this->currency}'.");
        }
    }

    public function entityType(): EntityType
    {
        return EntityType::Bill;
    }

    public function localId(): ?string
    {
        return $this->localId;
    }

    /**
     * The vendor as a contact, whether the caller passed a name or a full record.
     */
    public function vendorContact(): ContactData
    {
        return $this->vendor instanceof ContactData
            ? $this->vendor
            : ContactData::vendor($this->vendor);
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
