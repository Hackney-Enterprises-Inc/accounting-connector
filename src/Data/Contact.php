<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;

/**
 * A contact as the provider already holds it.
 *
 * The read counterpart of {@see ContactData}, which is a write payload. This exists
 * for the one question a match asks about contacts: "does the customer already
 * have one by this name", answered without creating anything. A host that gets
 * null back decides what that means; the package never mints a contact on a read.
 */
final readonly class Contact
{
    public function __construct(
        /** The provider's own id, the value a BankTransactionChange sets. */
        public string $id,
        public string $name,
        /** Provider-native status: for Xero ACTIVE, ARCHIVED or GDPRREQUEST. */
        public ?string $status = null,
        /**
         * The provider's supplier flag. For Xero, true only once an accounts payable
         * bill exists against the contact; a vendor paid only by card or bank transfer
         * stays false. Information, not a filter.
         */
        public bool $isSupplier = false,
        public bool $isCustomer = false,
        /** When the contact was merged into another, that contact's id (it is archived). */
        public ?string $mergedToContactId = null,
        /** The provider's last-modified instant for the contact. */
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === null || strtoupper($this->status) === 'ACTIVE';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'is_supplier' => $this->isSupplier,
            'is_customer' => $this->isCustomer,
            'merged_to_contact_id' => $this->mergedToContactId,
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * Rebuild from toArray(). A payload stored before merged_to_contact_id and
     * updated_at existed hydrates with both null.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $updatedAt = null;

        if (is_string($data['updated_at'] ?? null) && $data['updated_at'] !== '') {
            try {
                $updatedAt = new DateTimeImmutable($data['updated_at']);
            } catch (\Exception) {
                $updatedAt = null;
            }
        }

        $merged = $data['merged_to_contact_id'] ?? null;

        return new self(
            id: (string) $data['id'],
            name: (string) ($data['name'] ?? ''),
            status: isset($data['status']) && $data['status'] !== '' ? (string) $data['status'] : null,
            isSupplier: (bool) ($data['is_supplier'] ?? false),
            isCustomer: (bool) ($data['is_customer'] ?? false),
            mergedToContactId: is_string($merged) && $merged !== '' ? $merged : null,
            updatedAt: $updatedAt,
        );
    }
}
