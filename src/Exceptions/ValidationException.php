<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Enums\Provider;

/**
 * The provider rejected the payload on its own business rules (400 / 422), or the
 * connector refused to send one it could prove the provider would mishandle.
 *
 * Never retryable as-is. The message carries the provider's own wording, which is
 * usually the only thing that tells a bookkeeper what to fix. A refusal the
 * connector made itself names why in `$reason`, one of the REASON_* constants, so
 * a host can record it without parsing the message.
 */
final class ValidationException extends AccountingConnectorException
{
    /**
     * A line's tax has been adjusted by hand and the provider would recompute it.
     * The BankTransactions endpoint ignores a supplied TaxAmount, so the override
     * cannot be sent back; the only way to keep it is not to write.
     */
    public const REASON_TAX_OVERRIDE_WOULD_BE_LOST = 'tax_override_would_be_lost';

    /**
     * The provider did not say whether the line amounts include tax, and a write
     * that guessed would default to Inclusive on Xero.
     */
    public const REASON_TAX_MODE_UNKNOWN = 'tax_mode_unknown';

    /**
     * A taxed line uses a rate the lookup does not know, so the tax cannot be
     * proved untouched. Refreshing the lookups usually clears it.
     */
    public const REASON_TAX_RATE_UNKNOWN = 'tax_rate_unknown';

    /**
     * The transaction's type is one this connector has no case for, so a replacing
     * write could not send it back; a defaulted type would change what the
     * transaction is (a SPEND for a money-in line). Refused before any request.
     */
    public const REASON_TYPE_UNKNOWN = 'type_unknown';

    /**
     * The transaction is a transfer, overpayment or prepayment leg: recognised, but
     * not something a document is ever matched to or that a recode may touch
     * (only SPEND and RECEIVE are). Refused before any request.
     */
    public const REASON_TYPE_NOT_RECODABLE = 'type_not_recodable';

    /**
     * @param  array<int, string>  $errors  Individual provider validation messages.
     */
    public function __construct(
        string $message,
        ?Provider $provider = null,
        public readonly array $errors = [],
        ?string $providerMessage = null,
        /** Why the connector refused, when it was the connector and not the provider. */
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message, $provider, $providerMessage);
    }
}
