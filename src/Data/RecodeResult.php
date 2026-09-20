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
    /**
     * @param  bool  $recovered  True when no write was made because the connector's
     *                           read already showed the change applied: an earlier
     *                           attempt landed and its response was lost. `$before`
     *                           is then the expectation laid over that read (the
     *                           codes the decision was made against, amounts as the
     *                           read holds them), not a read of its own.
     */
    public function __construct(
        public BankTransactionData $before,
        public BankTransactionData $after,
        public bool $recovered = false,
    ) {}

    /**
     * Whether the write changed any line's account code at all.
     */
    public function changedCoding(): bool
    {
        return $this->before->accountCodesByLine() !== $this->after->accountCodesByLine();
    }
}
