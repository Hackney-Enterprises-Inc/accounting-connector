<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Http;

use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\RateLimitException;
use Throwable;

/**
 * The host's say over whether a request may leave right now.
 *
 * Asked once before every attempt {@see HttpClient} makes, retries included. The
 * package's own retry policy only reacts to a limit after the provider has refused
 * a request; this is where a host spends its allowance deliberately instead. Xero
 * meters each app per connected organisation (sixty calls a minute, five thousand a
 * day, five in flight), and a background walk of four years of bank transactions
 * has to leave room for the approval that lands in the middle of it.
 *
 * An implementation may block briefly until a slot is free, or throw
 * {@see RateLimitException} to refuse without a request being made; the exception
 * reaches the caller exactly as a provider's own 429 would after the retry budget.
 * It must not throw anything else.
 *
 * Both arguments are nullable because an OAuth exchange has no tenant yet and a
 * host may call the client for something that is not a provider at all; an
 * implementation keys such calls however it likes.
 *
 * Every attempt the gate let through ends in one of two ways: a response, which
 * the client hands to its {@see HttpClient::afterResponse()} listeners, or a
 * transport failure (a timeout, a reset, a DNS miss) with no response at all. The
 * second is {@see release()}: whatever the gate reserved for the attempt (an
 * in-flight slot, most likely) is handed back, so four timeouts in a row cannot
 * leave a tenant looking as though four requests were still in flight.
 */
interface RequestGate
{
    /**
     * @throws RateLimitException to refuse the attempt outright
     */
    public function acquire(?Provider $provider, ?string $tenantId): void;

    /**
     * An attempt the gate admitted produced no response.
     *
     * Must not throw; the client is already reporting or retrying the failure.
     */
    public function release(?Provider $provider, ?string $tenantId, Throwable $failure): void;
}
