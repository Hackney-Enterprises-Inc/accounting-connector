<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Enums\Provider;

/**
 * The provider accepted a recode and changed an amount while doing it.
 *
 * The one failure a recode cannot undo: the write has landed, and sending the
 * original figures back would not restore whatever the provider recomputed. Both
 * states are carried so a host can put them in front of a person, who fixes the
 * transaction in the provider's own interface.
 *
 * A sibling of ValidationException rather than a subclass only because that class
 * is final; a host that catches ValidationException for "the provider refused"
 * should catch this one separately, because the provider did not refuse.
 */
final class RecodeMovedMoneyException extends AccountingConnectorException
{
    /**
     * @param  array<int, string>  $differences  What moved, in words, one entry each.
     */
    public function __construct(
        string $message,
        public readonly BankTransactionData $before,
        public readonly BankTransactionData $after,
        public readonly array $differences = [],
        ?Provider $provider = null,
    ) {
        parent::__construct($message, $provider);
    }
}
