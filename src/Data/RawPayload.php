<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * A provider-native payload, passed straight through untouched.
 *
 * The escape hatch. Use it when a provider supports a field the canonical DTOs do
 * not model and waiting for the package to grow one is not an option, or when
 * porting existing code that already builds provider-shaped arrays and you want to
 * move one call site at a time.
 *
 * The cost is that the call is no longer portable: a RawPayload built for Xero
 * will not post to QuickBooks. That is why the provider is declared up front and
 * checked, rather than discovered as a confusing 400 from the wrong vendor.
 *
 * Contact resolution still happens. A raw Xero payload may carry
 * `Contact: ['Name' => '...']` and a raw QuickBooks payload may carry
 * `VendorName`, exactly as the connectors' own output does, and both are resolved
 * to a real contact before the post.
 */
final readonly class RawPayload implements EntityPayload
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        private EntityType $type,
        public Provider $provider,
        public array $body,
        public ?string $localId = null,
    ) {
        if ($this->body === []) {
            throw new InvalidPayloadException('A raw payload cannot be empty.');
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function forXero(EntityType $type, array $body, ?string $localId = null): self
    {
        return new self($type, Provider::Xero, $body, $localId);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function forQuickBooks(EntityType $type, array $body, ?string $localId = null): self
    {
        return new self($type, Provider::QuickBooksOnline, $body, $localId);
    }

    public function entityType(): EntityType
    {
        return $this->type;
    }

    public function localId(): ?string
    {
        return $this->localId;
    }

    /**
     * Refuse to hand a payload to the provider it was not written for.
     */
    public function assertMatches(Provider $provider): void
    {
        if ($this->provider !== $provider) {
            throw new InvalidPayloadException(sprintf(
                'This raw payload was built for %s and cannot be posted to %s.',
                $this->provider->label(),
                $provider->label(),
            ), $provider);
        }
    }
}
