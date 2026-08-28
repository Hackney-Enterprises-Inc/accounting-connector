<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * An amount in integer minor units.
 *
 * Both consuming applications store money as integer cents and neither should
 * have to know that Xero and QuickBooks both want decimal numbers on the wire.
 * The conversion happens once, here, at the boundary.
 *
 * Currency is deliberately absent: both providers carry it on the document, not
 * the line, so it lives on the payload DTOs instead.
 */
final readonly class Money
{
    private function __construct(
        public int $amount,
    ) {}

    /**
     * Build from integer minor units, which is how both host applications store money.
     */
    public static function cents(int $amount): self
    {
        return new self($amount);
    }

    /**
     * Build from a decimal figure, for the rare case a caller genuinely holds one.
     *
     * Rounds half up to the nearest minor unit, matching how both providers round.
     */
    public static function fromDecimal(float|string $amount): self
    {
        if (! is_numeric($amount)) {
            throw new InvalidPayloadException("Cannot build Money from a non-numeric value: {$amount}");
        }

        return new self((int) round(((float) $amount) * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * The decimal figure the provider APIs expect in their JSON payloads.
     */
    public function toDecimal(): float
    {
        return round($this->amount / 100, 2);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function plus(self $other): self
    {
        return new self($this->amount + $other->amount);
    }

    public function times(float $multiplier): self
    {
        return new self((int) round($this->amount * $multiplier));
    }

    public function negated(): self
    {
        return new self(-$this->amount);
    }
}
