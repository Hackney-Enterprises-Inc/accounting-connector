<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Enums\Provider;

/**
 * The provider rejected the payload on its own business rules (400 / 422).
 *
 * Never retryable as-is. The message carries the provider's own wording, which is
 * usually the only thing that tells a bookkeeper what to fix.
 */
final class ValidationException extends AccountingConnectorException
{
    /**
     * @param  array<int, string>  $errors  Individual provider validation messages.
     */
    public function __construct(
        string $message,
        ?Provider $provider = null,
        public readonly array $errors = [],
        ?string $providerMessage = null,
    ) {
        parent::__construct($message, $provider, $providerMessage);
    }
}
