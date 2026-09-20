<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Enums\Provider;

/**
 * The provider's record no longer matches what a write was decided against.
 *
 * Raised before any request is made, so nothing has changed anywhere. The fresh
 * copy the connector read is carried so the host can refresh its mirror from it
 * and decide again, rather than paying for another read.
 */
final class PreconditionFailedException extends AccountingConnectorException
{
    /**
     * @param  array<int, string>  $differences  What differed, in words, one entry each.
     */
    public function __construct(
        string $message,
        public readonly BankTransactionData $fresh,
        public readonly array $differences = [],
        ?Provider $provider = null,
    ) {
        parent::__construct($message, $provider);
    }
}
