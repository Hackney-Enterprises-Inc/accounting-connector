<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Enums\Provider;
use RuntimeException;

/**
 * Base for everything this package throws. Catch this to catch all of it.
 */
class AccountingConnectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Provider $provider = null,
        public readonly ?string $providerMessage = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
