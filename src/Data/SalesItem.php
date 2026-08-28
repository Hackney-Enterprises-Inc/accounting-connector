<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Contracts\LookupRecord;

/**
 * A QuickBooks product/service item, kept for invoice-line resolution.
 *
 * QuickBooks sales lines are item-based: a SalesItemLineDetail's ItemAccountRef is
 * ignored on create (verified against the live API), so the only way a line posts
 * to a chosen income account is through an ItemRef whose item is wired to that
 * account. The connector keeps this list cached per connection and find-or-creates
 * a Service item per income account as needed.
 *
 * Xero has no equivalent concept; its lines address accounts directly.
 */
final readonly class SalesItem implements LookupRecord
{
    public function __construct(
        public string $id,
        public string $name,
        /** The income account this item posts to, when the provider says. */
        public ?string $incomeAccountId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'income_account_id' => $this->incomeAccountId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            incomeAccountId: isset($data['income_account_id']) ? (string) $data['income_account_id'] : null,
        );
    }
}
