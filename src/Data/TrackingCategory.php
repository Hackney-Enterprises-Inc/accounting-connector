<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Contracts\LookupRecord;

/**
 * A Xero tracking category and the options a line item can be tagged with.
 *
 * Xero-only. QuickBooks has no equivalent, so its connector returns an empty list
 * rather than throwing: a host rendering a tracking dropdown gets nothing to show,
 * which is the truth, instead of an error it has to special-case per provider.
 *
 * Archived categories and archived options are filtered out on the way in. Xero
 * keeps returning them long after a customer has stopped using them, and offering
 * an archived option in a dropdown produces a post that Xero then rejects.
 */
final readonly class TrackingCategory implements LookupRecord
{
    /**
     * @param  array<int, TrackingOption>  $options
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $options = [],
    ) {}

    /**
     * Build the reference a LineItem needs to be tagged with one of these options.
     */
    public function ref(string $optionId): ?TrackingRef
    {
        foreach ($this->options as $option) {
            if ($option->id === $optionId) {
                return new TrackingRef(
                    categoryId: $this->id,
                    optionId: $option->id,
                    categoryName: $this->name,
                    optionName: $option->name,
                );
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'options' => array_map(fn (TrackingOption $o): array => $o->toArray(), $this->options),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $options = [];

        foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $option) {
            if (is_array($option)) {
                $options[] = TrackingOption::fromArray($option);
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            options: $options,
        );
    }
}
