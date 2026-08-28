<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * One selectable value within a Xero tracking category.
 */
final readonly class TrackingOption
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
        );
    }
}
