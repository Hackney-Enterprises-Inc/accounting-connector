<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * Identifying details of the connected company.
 *
 * Shown back to the customer after connecting so they can confirm they linked the
 * right organization, which matters when a bookkeeper is signed in to several.
 */
final readonly class TenantInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $legalName = null,
        public ?string $countryCode = null,
        public ?string $currencyCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'country_code' => $this->countryCode,
            'currency_code' => $this->currencyCode,
        ];
    }
}
