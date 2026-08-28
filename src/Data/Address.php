<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * A postal address on a contact. Every part is optional; providers accept partials.
 */
final readonly class Address
{
    public function __construct(
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $country = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->line1 === null
            && $this->line2 === null
            && $this->city === null
            && $this->region === null
            && $this->postalCode === null
            && $this->country === null;
    }
}
