<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Data\InvoiceState;
use Hei\AccountingConnector\Enums\Provider;

/**
 * The invoice cannot be voided because money is applied to it.
 *
 * Xero will not void or delete an invoice that has a payment, credit note,
 * prepayment or overpayment allocated; the person has to remove those in Xero
 * first, and only then can the void go through. That is a message for a human,
 * not a retry, so it is its own type rather than a ValidationException a host
 * would have to parse.
 *
 * `$state` is the connector's read of the invoice at the moment it refused, or
 * null when the refusal came from the provider after the write was attempted;
 * `providerMessage` then carries the provider's own wording.
 */
final class InvoiceHasPaymentsException extends AccountingConnectorException
{
    public function __construct(
        string $message,
        public readonly ?InvoiceState $state = null,
        ?Provider $provider = null,
        ?string $providerMessage = null,
    ) {
        parent::__construct($message, $provider, $providerMessage);
    }
}
