<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Contracts\LookupRecord;

/**
 * A tax rate available on the connected company.
 *
 * `$reference` is what goes on LineItem::$taxCode. Xero uses a named TaxType such
 * as INPUT2 or NONE; QuickBooks uses a TaxCodeRef id.
 *
 * `$rate` is a percentage, so 15% GST is 15.0 and not 0.15. Neither provider is
 * consistent about this in its own responses, so it is normalised on the way in.
 */
final readonly class TaxCode implements LookupRecord
{
    public function __construct(
        public string $reference,
        public string $name,
        public float $rate = 0.0,
        public bool $isSalesTax = false,
        public bool $isPurchaseTax = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'name' => $this->name,
            'rate' => $this->rate,
            'is_sales_tax' => $this->isSalesTax,
            'is_purchase_tax' => $this->isPurchaseTax,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reference: (string) $data['reference'],
            name: (string) $data['name'],
            rate: (float) ($data['rate'] ?? 0.0),
            isSalesTax: (bool) ($data['is_sales_tax'] ?? false),
            isPurchaseTax: (bool) ($data['is_purchase_tax'] ?? false),
        );
    }
}
