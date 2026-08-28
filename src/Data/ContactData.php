<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * A customer or a vendor. The same fields describe both; only the role differs.
 *
 * Xero calls both a Contact and flags it IsCustomer or IsSupplier. QuickBooks has
 * two separate resources, Customer and Vendor. The connectors handle that split.
 */
final readonly class ContactData implements EntityPayload
{
    /**
     * @param  EntityType::Customer|EntityType::Vendor  $role
     */
    public function __construct(
        public string $name,
        public EntityType $role = EntityType::Vendor,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $taxNumber = null,
        public ?Address $address = null,
        /** The host's own id for this contact, used as the entity-map key. */
        public ?string $localId = null,
    ) {
        if (! $role->isContact()) {
            throw new InvalidPayloadException(
                "ContactData role must be customer or vendor, got '{$role->value}'."
            );
        }

        if (trim($this->name) === '') {
            throw new InvalidPayloadException('A contact needs a name.');
        }
    }

    public static function vendor(string $name, ?string $localId = null): self
    {
        return new self(name: $name, role: EntityType::Vendor, localId: $localId);
    }

    public static function customer(string $name, ?string $localId = null): self
    {
        return new self(name: $name, role: EntityType::Customer, localId: $localId);
    }

    public function entityType(): EntityType
    {
        return $this->role;
    }

    /**
     * Never null, unlike the other payloads.
     *
     * A contact always has a key: it falls back to a normalised name, because a
     * vendor that only ever existed as text on a receipt has no host-side id to key
     * on and still has to be found again on the next sync.
     */
    public function localId(): string
    {
        return $this->mapKey();
    }

    /**
     * The key this contact is cached under in the entity map.
     *
     * Falls back to a normalised name when the host has no id of its own, which is
     * the common case for a vendor that only ever existed as text on a receipt.
     */
    public function mapKey(): string
    {
        return $this->localId ?? 'name:'.mb_strtolower(trim($this->name));
    }
}
