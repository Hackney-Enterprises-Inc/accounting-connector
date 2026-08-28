<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Enums\Provider;

/**
 * The provider rate-limited us and the retry budget ran out.
 *
 * Xero's limits are per tenant: 60 calls a minute, 5,000 a day once the app is
 * certified (1,000 before), and no more than 5 requests in flight at once. The
 * per-minute ceiling is shared across every app connected to that organization,
 * so a customer running two integrations can rate-limit us through no fault of
 * our own. Treat this as retryable on a later queue attempt, not as a failure.
 */
final class RateLimitException extends AccountingConnectorException
{
    public function __construct(
        string $message,
        ?Provider $provider = null,
        /** Seconds the provider asked us to wait, from the Retry-After header. */
        public readonly ?int $retryAfter = null,
        ?string $providerMessage = null,
    ) {
        parent::__construct($message, $provider, $providerMessage);
    }
}
