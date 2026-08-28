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
 * Money already spent: a receipt, a card charge, a spend-money transaction.
 *
 * Posts as a Xero SPEND BankTransaction or a QuickBooks Purchase.
 *
 * `$bankAccount` is not optional in practice. Xero rejects a SPEND transaction
 * without a bank account, and QuickBooks rejects a Purchase without an AccountRef.
 * It may be left null here only so that it can be filled from the connection's
 * settings at post time; if neither supplies one the connector throws before
 * making any HTTP call.
 */
final readonly class ExpenseData implements EntityPayload
{
    /**
     * @param  array<int, LineItem>  $lines
     * @param  string|null  $bankAccount  Xero bank account id, or QuickBooks account id.
     * @param  string|null  $paymentMethod  QuickBooks PaymentType: Cash, Check or CreditCard. Ignored by Xero.
     */
    public function __construct(
        public ContactData|string $vendor,
        public DateTimeImmutable $date,
        public array $lines,
        public string $currency = 'USD',
        public ?string $bankAccount = null,
        public ?string $reference = null,
        public ?string $paymentMethod = null,
        public TransactionStatus $status = TransactionStatus::Authorised,
        public LineAmountType $lineAmountType = LineAmountType::Exclusive,
        public ?string $localId = null,
    ) {
        if ($this->lines === []) {
            throw new InvalidPayloadException('An expense needs at least one line item.');
        }

        if (strlen($this->currency) !== 3) {
            throw new InvalidPayloadException("Currency must be a 3-letter ISO code, got '{$this->currency}'.");
        }
    }

    public function entityType(): EntityType
    {
        return EntityType::Expense;
    }

    public function localId(): ?string
    {
        return $this->localId;
    }

    public function vendorContact(): ContactData
    {
        return $this->vendor instanceof ContactData
            ? $this->vendor
            : ContactData::vendor($this->vendor);
    }

    /**
     * A copy posting against a specific bank account.
     *
     * A convenience for hosts that resolve the account themselves before calling;
     * the connectors read the "bank_account" connection setting directly and never
     * call this.
     */
    public function withBankAccount(string $bankAccount): self
    {
        return new self(
            vendor: $this->vendor,
            date: $this->date,
            lines: $this->lines,
            currency: $this->currency,
            bankAccount: $bankAccount,
            reference: $this->reference,
            paymentMethod: $this->paymentMethod,
            status: $this->status,
            lineAmountType: $this->lineAmountType,
            localId: $this->localId,
        );
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
