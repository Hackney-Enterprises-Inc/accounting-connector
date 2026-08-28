<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * A payment received against an invoice.
 *
 * Posts as a Xero Payment or a QuickBooks Payment.
 *
 * `$invoiceExternalId` is the provider's id for the invoice, not the host's. Read
 * it back from the entity map after the invoice itself was created: applying a
 * payment to a local id the provider has never seen is the single most common way
 * this call fails.
 */
final readonly class PaymentData implements EntityPayload
{
    /**
     * @param  string|null  $account  Xero bank account id, or QuickBooks DepositToAccountRef. Falls back to connection settings.
     */
    public function __construct(
        public string $invoiceExternalId,
        public Money $amount,
        public DateTimeImmutable $date,
        public string $currency = 'USD',
        public ?string $account = null,
        public ?string $reference = null,
        /** Required by QuickBooks, which attaches payments to a customer as well as an invoice. */
        public ContactData|string|null $customer = null,
        public ?string $localId = null,
    ) {
        if ($this->invoiceExternalId === '') {
            throw new InvalidPayloadException('A payment needs the external id of the invoice it settles.');
        }

        if ($this->amount->isZero()) {
            throw new InvalidPayloadException('A payment of zero cannot be posted.');
        }
    }

    public function entityType(): EntityType
    {
        return EntityType::Payment;
    }

    public function localId(): ?string
    {
        return $this->localId;
    }

    public function customerContact(): ?ContactData
    {
        if ($this->customer === null) {
            return null;
        }

        return $this->customer instanceof ContactData
            ? $this->customer
            : ContactData::customer($this->customer);
    }

    /**
     * A copy depositing into a specific account.
     *
     * A convenience for hosts that resolve the account themselves before calling;
     * the connectors read the "bank_account" connection setting directly and never
     * call this.
     */
    public function withAccount(string $account): self
    {
        return new self(
            invoiceExternalId: $this->invoiceExternalId,
            amount: $this->amount,
            date: $this->date,
            currency: $this->currency,
            account: $account,
            reference: $this->reference,
            customer: $this->customer,
            localId: $this->localId,
        );
    }
}
