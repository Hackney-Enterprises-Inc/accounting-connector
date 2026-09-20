<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Enums\LineAmountType;

/**
 * A bank transaction that already exists in the customer's books.
 *
 * The read counterpart of {@see ExpenseData}. A host that captures receipts needs
 * this to answer "is the spend on this receipt already in Xero", which is the
 * difference between attaching the receipt to the transaction the bank feed already
 * created and posting a second transaction for the same money.
 *
 * Amounts are integer minor units throughout, like everything else that crosses
 * this boundary. `$total` is what a matcher compares a receipt against: it is the
 * gross figure including tax, which is what a bank feed line shows and therefore
 * what the receipt total should equal.
 *
 * `$lineAmountType` and `$currencyRate` are here for the write that reads this
 * first. Xero replaces a transaction whole on POST, defaults an omitted tax mode to
 * Inclusive and recomputes an omitted currency rate, so a recode that did not carry
 * them back would move the money it was told not to touch.
 */
final readonly class BankTransactionData
{
    /**
     * @param  array<int, BankTransactionLine>  $lines
     */
    public function __construct(
        public string $id,
        public ?BankTransactionType $type,
        public ?DateTimeImmutable $date,
        public Money $total,
        public ?Money $subTotal = null,
        public ?Money $totalTax = null,
        public ?string $currency = null,
        public ?string $status = null,
        public ?string $contactId = null,
        public ?string $contactName = null,
        public ?string $bankAccountId = null,
        public ?string $bankAccountName = null,
        public ?string $reference = null,
        public bool $isReconciled = false,
        public bool $hasAttachments = false,
        public array $lines = [],
        public ?DateTimeImmutable $updatedDateUtc = null,
        /** Whether the line amounts include tax. Null when the provider did not say. */
        public ?LineAmountType $lineAmountType = null,
        /** The rate a foreign-currency transaction was booked at, when there is one. */
        public ?float $currencyRate = null,
    ) {}

    /**
     * Whether anybody has coded any line on this transaction to an account.
     *
     * A bank feed creates a transaction with no coding at all, which is the case a
     * host can safely push its own account code into. One that is already coded is a
     * bookkeeper's decision and overwriting it silently is how a host loses trust.
     */
    public function isCoded(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->isCoded()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The account codes currently on this transaction, deduplicated.
     *
     * @return array<int, string>
     */
    public function accountCodes(): array
    {
        $codes = [];

        foreach ($this->lines as $line) {
            // A list rather than a keyed set: PHP folds a numeric string array key
            // to an integer, and Xero account codes are numeric strings, so '429'
            // would come back out of array_keys() as the integer 429.
            if ($line->accountCode !== null && $line->accountCode !== '' && ! in_array($line->accountCode, $codes, true)) {
                $codes[] = $line->accountCode;
            }
        }

        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'date' => $this->date?->format('Y-m-d'),
            'total' => $this->total->amount,
            'sub_total' => $this->subTotal?->amount,
            'total_tax' => $this->totalTax?->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'contact_id' => $this->contactId,
            'contact_name' => $this->contactName,
            'bank_account_id' => $this->bankAccountId,
            'bank_account_name' => $this->bankAccountName,
            'reference' => $this->reference,
            'is_reconciled' => $this->isReconciled,
            'has_attachments' => $this->hasAttachments,
            'lines' => array_map(static fn (BankTransactionLine $line): array => $line->toArray(), $this->lines),
            'updated_date_utc' => $this->updatedDateUtc?->format(DATE_ATOM),
            'line_amount_type' => $this->lineAmountType?->value,
            'currency_rate' => $this->currencyRate,
        ];
    }

    /**
     * The account code on each line, keyed by line id, in line order.
     *
     * The shape a recode expectation compares: what a decision was made against
     * and what the connector finds when it reads again. A line with no id is keyed
     * by its position, so a transaction whose lines Xero has not yet numbered still
     * compares line for line.
     *
     * @return array<string, string|null>
     */
    public function accountCodesByLine(): array
    {
        $codes = [];

        foreach ($this->lines as $index => $line) {
            $codes[$line->lineItemId ?? '#'.$index] = $line->accountCode;
        }

        return $codes;
    }

    /**
     * This transaction with its lines' account codes replaced and, when given, its
     * modification stamp: the state a decision was made against, rebuilt from the
     * connector's later read and the expectation. Amounts are the read's own; a
     * recode never moves them, so they are the same on both sides.
     *
     * @param  array<string, string|null>  $accountCodesByLine  Line id (or `#index`) to account code.
     */
    public function withAccountCodes(array $accountCodesByLine, ?DateTimeImmutable $updatedDateUtc = null): self
    {
        $lines = [];

        foreach ($this->lines as $index => $line) {
            $key = $line->lineItemId ?? '#'.$index;

            $lines[] = array_key_exists($key, $accountCodesByLine)
                ? $line->withAccountCode($accountCodesByLine[$key])
                : $line;
        }

        return new self(
            id: $this->id,
            type: $this->type,
            date: $this->date,
            total: $this->total,
            subTotal: $this->subTotal,
            totalTax: $this->totalTax,
            currency: $this->currency,
            status: $this->status,
            contactId: $this->contactId,
            contactName: $this->contactName,
            bankAccountId: $this->bankAccountId,
            bankAccountName: $this->bankAccountName,
            reference: $this->reference,
            isReconciled: $this->isReconciled,
            hasAttachments: $this->hasAttachments,
            lines: $lines,
            updatedDateUtc: $updatedDateUtc ?? $this->updatedDateUtc,
            lineAmountType: $this->lineAmountType,
            currencyRate: $this->currencyRate,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $lines = [];

        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (is_array($line)) {
                $lines[] = BankTransactionLine::fromArray($line);
            }
        }

        $date = static function (mixed $value): ?DateTimeImmutable {
            if (! is_string($value) || $value === '') {
                return null;
            }

            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        };

        return new self(
            id: (string) $data['id'],
            type: BankTransactionType::tryFromXero(isset($data['type']) ? (string) $data['type'] : null),
            date: $date($data['date'] ?? null),
            total: Money::cents((int) ($data['total'] ?? 0)),
            subTotal: isset($data['sub_total']) ? Money::cents((int) $data['sub_total']) : null,
            totalTax: isset($data['total_tax']) ? Money::cents((int) $data['total_tax']) : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
            contactId: isset($data['contact_id']) ? (string) $data['contact_id'] : null,
            contactName: isset($data['contact_name']) ? (string) $data['contact_name'] : null,
            bankAccountId: isset($data['bank_account_id']) ? (string) $data['bank_account_id'] : null,
            bankAccountName: isset($data['bank_account_name']) ? (string) $data['bank_account_name'] : null,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            isReconciled: (bool) ($data['is_reconciled'] ?? false),
            hasAttachments: (bool) ($data['has_attachments'] ?? false),
            lines: $lines,
            updatedDateUtc: $date($data['updated_date_utc'] ?? null),
            lineAmountType: isset($data['line_amount_type']) ? LineAmountType::tryFrom((string) $data['line_amount_type']) : null,
            currencyRate: isset($data['currency_rate']) && is_numeric($data['currency_rate']) ? (float) $data['currency_rate'] : null,
        );
    }
}
