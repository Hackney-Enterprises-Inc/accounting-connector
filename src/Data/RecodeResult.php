<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * A recode that was accepted: the transaction as the connector read it just before
 * the write, and as the provider returned it just after.
 *
 * `$before` is the connector's own read, never the host's mirror. A journal that
 * records what a recode replaced has to record what was actually there at the
 * moment of writing, and only the connector saw that.
 */
final readonly class RecodeResult
{
    public function __construct(
        public BankTransactionData $before,
        public BankTransactionData $after,
    ) {}

    /**
     * Whether the write changed any line's account code at all.
     */
    public function changedCoding(): bool
    {
        return $this->before->accountCodesByLine() !== $this->after->accountCodesByLine();
    }
}
