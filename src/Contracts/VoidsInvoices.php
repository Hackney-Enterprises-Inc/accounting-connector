<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\InvoiceState;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\InvoiceHasPaymentsException;
use Hei\AccountingConnector\Exceptions\ValidationException;

/**
 * Reading the current state of an invoice or bill the host posted, and voiding it.
 *
 * Optional, like {@see CodesBankTransactions}: a host asks with `instanceof`. It
 * exists for one situation: a document that was posted as a bill and should not
 * have been (the receipt for a card payment the bank feed already holds, say).
 * The bill has to be taken out of the customer's books before the document can
 * be matched to the transaction that really paid for it, and Xero has no
 * delete for an approved invoice, only a void.
 *
 * Bills and sales invoices are the same provider resource on Xero, so one pair of
 * methods covers both; the state's `type` says which it is.
 */
interface VoidsInvoices
{
    /**
     * The invoice as the provider holds it now, or null when it no longer exists.
     *
     * The read a host makes before any write to a posted bill: Xero refuses to
     * modify a PAID, VOIDED or DELETED invoice, and only this read says which of
     * those the bill has become since it was posted.
     *
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function findInvoice(Connection $connection, string $invoiceId): ?InvoiceState;

    /**
     * Void the invoice and return it as the provider reports it afterwards.
     *
     * One answer for every way of being gone: an AUTHORISED invoice is VOIDED, a
     * DRAFT or SUBMITTED one is DELETED (the only status Xero accepts for those),
     * an invoice already voided or deleted is returned as it is, and one the
     * provider no longer has at all is returned as VOIDED by id. The status is
     * re-read after the write, so the returned state is what the provider holds,
     * not what was asked for.
     *
     * Money applied to the invoice stops a void: a payment, credit note,
     * prepayment or overpayment has to be removed in the provider first. That
     * refusal is {@see InvoiceHasPaymentsException}, thrown before any write when
     * the read already shows it and after the write when the provider says so,
     * carrying the provider's wording for the person who has to act on it.
     *
     * `$idempotencyKey`, when given, is sent so a retried void after a lost
     * response is answered with the same result rather than refused as a second
     * modification.
     *
     * @throws InvoiceHasPaymentsException when money is applied to the invoice
     * @throws ValidationException when the provider refuses for any other reason, or reports the invoice still live after accepting the void
     * @throws InvalidPayloadException when no id is given
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function voidInvoice(Connection $connection, string $invoiceId, ?string $idempotencyKey = null): InvoiceState;
}
