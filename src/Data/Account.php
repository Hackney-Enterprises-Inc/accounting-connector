<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Contracts\LookupRecord;
use Hei\AccountingConnector\Enums\AccountClass;

/**
 * One account from the connected company's chart of accounts.
 *
 * `$reference` is the value to put on a LineItem::$accountCode. It differs by
 * provider on purpose: Xero lines address an account by its short code, while
 * QuickBooks lines address it by an opaque per-company id. Reading it from here
 * rather than assembling it means calling code never has to know which.
 */
final readonly class Account implements LookupRecord
{
    public function __construct(
        /** The provider's own id for the account. */
        public string $id,
        public string $name,
        /** Xero's account code, or QuickBooks' AcctNum. Often absent in QuickBooks. */
        public ?string $code = null,
        /** Provider-native type string, for example EXPENSE, BANK, Credit Card. */
        public ?string $type = null,
        /** The provider-native type folded into a portable classification. */
        public AccountClass $class = AccountClass::Other,
        /** The value a line item should carry to post against this account. */
        public ?string $reference = null,
        public ?string $currency = null,
        public ?string $bankAccountNumber = null,
        /**
         * Xero's system account marker (DEBTORS, CREDITORS, GST, ...) when the account
         * is one Xero manages itself. A host offering accounts as a holding set or a
         * rule target has to be able to leave these out.
         */
        public ?string $systemAccount = null,
    ) {}

    /**
     * What a LineItem should use as its accountCode to hit this account.
     */
    public function lineReference(): string
    {
        return $this->reference ?? $this->code ?? $this->id;
    }

    /**
     * Whether an expense or bill line can be coded to this account.
     */
    public function isExpense(): bool
    {
        return $this->class === AccountClass::Expense;
    }

    /**
     * Whether an invoice line can be coded to this account.
     */
    public function isRevenue(): bool
    {
        return $this->class === AccountClass::Revenue;
    }

    /**
     * Whether money can be spent from or received into this account.
     */
    public function isBank(): bool
    {
        return $this->class === AccountClass::Bank;
    }

    /**
     * Whether the provider manages this account itself.
     *
     * Xero returns the marker as "" or null on ordinary accounts; both read as no.
     */
    public function isSystem(): bool
    {
        return $this->systemAccount !== null && $this->systemAccount !== '';
    }

    /**
     * Narrow a list of accounts to one classification.
     *
     * @param  array<int, self>  $accounts
     * @return array<int, self>
     */
    public static function only(array $accounts, AccountClass $class): array
    {
        return array_values(array_filter(
            $accounts,
            fn (self $account): bool => $account->class === $class,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'class' => $this->class->value,
            'reference' => $this->lineReference(),
            'currency' => $this->currency,
            'bank_account_number' => $this->bankAccountNumber,
            'system_account' => $this->systemAccount,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            code: isset($data['code']) ? (string) $data['code'] : null,
            type: isset($data['type']) ? (string) $data['type'] : null,
            class: isset($data['class']) ? AccountClass::from((string) $data['class']) : AccountClass::Other,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            bankAccountNumber: isset($data['bank_account_number']) ? (string) $data['bank_account_number'] : null,
            systemAccount: isset($data['system_account']) && $data['system_account'] !== '' ? (string) $data['system_account'] : null,
        );
    }
}
