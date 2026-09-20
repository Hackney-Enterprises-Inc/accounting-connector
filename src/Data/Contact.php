<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

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
        /** Provider-native status, for example ACTIVE or ARCHIVED. */
        public ?string $status = null,
        public bool $isSupplier = false,
        public bool $isCustomer = false,
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
        ];
    }
}
