<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * A Xero tracking category selection on a line item.
 *
 * QuickBooks has no equivalent concept and the QuickBooks connector drops these
 * silently rather than failing the post: a tracking dimension the destination
 * cannot represent is a reporting nicety, not a reason to refuse a transaction.
 */
final readonly class TrackingRef
{
    public function __construct(
        public string $categoryId,
        public string $optionId,
        public ?string $categoryName = null,
        public ?string $optionName = null,
    ) {}

    /**
     * @param  array{category_id: string, option_id: string, category_name?: string|null, option_name?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            categoryId: $data['category_id'],
            optionId: $data['option_id'],
            categoryName: $data['category_name'] ?? null,
            optionName: $data['option_name'] ?? null,
        );
    }
}
