<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * One line as the provider currently holds it.
 *
 * Deliberately not {@see LineItem}. LineItem is a write payload: it is built by a
 * host that knows what it wants to post and it refuses to exist without an amount.
 * This is a read result, and a read has to be able to represent what is actually
 * there, including a line the customer coded to nothing at all.
 *
 * `$lineItemId` is the handle a coding update addresses. Xero replaces the whole
 * transaction on a POST, so a host that wants to change one line's account has to
 * send every line back, each carrying the id it came with, or Xero treats the
 * absent ones as deleted and the present ones as new.
 */
final readonly class BankTransactionLine
{
    /**
     * @param  array<int, TrackingRef>  $tracking
     */
    public function __construct(
        public ?string $lineItemId = null,
        public ?string $description = null,
        public ?float $quantity = null,
        public ?Money $unitAmount = null,
        public ?Money $lineAmount = null,
        /** Xero's short account code, the value a LineItem posts as accountCode. */
        public ?string $accountCode = null,
        /** Xero's opaque AccountID for the same account, when the response carries it. */
        public ?string $accountId = null,
        public ?string $taxType = null,
        public array $tracking = [],
    ) {}

    /**
     * Whether anybody has coded this line to an account yet.
     *
     * The question the matcher asks before deciding whether pushing the document's
     * own coding would overwrite a bookkeeper's work.
     */
    public function isCoded(): bool
    {
        return $this->accountCode !== null && $this->accountCode !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_item_id' => $this->lineItemId,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount?->amount,
            'line_amount' => $this->lineAmount?->amount,
            'account_code' => $this->accountCode,
            'account_id' => $this->accountId,
            'tax_type' => $this->taxType,
            'tracking' => array_map(static fn (TrackingRef $ref): array => [
                'category_id' => $ref->categoryId,
                'option_id' => $ref->optionId,
                'category_name' => $ref->categoryName,
                'option_name' => $ref->optionName,
            ], $this->tracking),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tracking = [];

        foreach (is_array($data['tracking'] ?? null) ? $data['tracking'] : [] as $ref) {
            if (is_array($ref) && isset($ref['category_id'], $ref['option_id'])) {
                /** @var array{category_id: string, option_id: string, category_name?: string|null, option_name?: string|null} $ref */
                $tracking[] = TrackingRef::fromArray($ref);
            }
        }

        return new self(
            lineItemId: isset($data['line_item_id']) ? (string) $data['line_item_id'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            quantity: isset($data['quantity']) ? (float) $data['quantity'] : null,
            unitAmount: isset($data['unit_amount']) ? Money::cents((int) $data['unit_amount']) : null,
            lineAmount: isset($data['line_amount']) ? Money::cents((int) $data['line_amount']) : null,
            accountCode: isset($data['account_code']) ? (string) $data['account_code'] : null,
            accountId: isset($data['account_id']) ? (string) $data['account_id'] : null,
            taxType: isset($data['tax_type']) ? (string) $data['tax_type'] : null,
            tracking: $tracking,
        );
    }
}
