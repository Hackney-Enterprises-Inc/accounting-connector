<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Enums\Provider;

/**
 * The provider rate-limited us and the retry budget ran out, or a RequestGate
 * refused the request before it was made.
 *
 * Xero's limits are per app per organisation: 60 calls a minute, 5,000 a day once
 * the app is certified (1,000 before), and no more than 5 requests in flight at
 * once. Another app the customer has connected spends its own allowance, so the
 * only way to hit these is to spend them ourselves. Treat this as retryable on a
 * later queue attempt, not as a failure; `providerMessage` names the limit
 * (`minute`, `day`, `concurrent`, or a gate's own reason) when it is known.
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
