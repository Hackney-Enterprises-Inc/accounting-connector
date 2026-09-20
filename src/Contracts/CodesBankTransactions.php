<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\RecodeExpectation;
use Hei\AccountingConnector\Data\RecodeResult;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\NotFoundException;
use Hei\AccountingConnector\Exceptions\PreconditionFailedException;
use Hei\AccountingConnector\Exceptions\RecodeMovedMoneyException;
use Hei\AccountingConnector\Exceptions\ValidationException;

/**
 * Changing how an existing bank transaction is coded, and nothing else about it.
 *
 * The narrow write that pairs with {@see ReadsBankTransactions}. Once a host has
 * decided a receipt belongs to a transaction the bank feed already created, the only
 * things it has any business changing are the account code and tracking on the
 * lines and, when the feed left it blank, the contact: the amount, date and bank
 * account came from the bank and are not ours to edit.
 *
 * Xero has no partial update - a POST replaces the transaction - so the
 * implementation has to read the current state and send it back with the coding
 * changed. That read-modify-write is the whole reason this is a method here rather
 * than something a host assembles from updateEntity(), and it is where the three
 * guarantees below are kept.
 */
interface CodesBankTransactions
{
    /**
     * Apply a change to an existing bank transaction and return before and after.
     *
     * Three guarantees, checked in this order:
     *
     *  1. Nothing is sent unless the provider's record still shows the coding the
     *     decision was made against. The connector reads the transaction itself
     *     immediately before writing; if `$expectation` disagrees with that read it
     *     throws {@see PreconditionFailedException} carrying the fresh copy, and no
     *     request leaves.
     *  2. Nothing is sent that the provider would recompute into different money.
     *     Xero defaults an omitted tax mode to Inclusive and ignores a supplied line
     *     TaxAmount, so a transaction whose tax mode is unknown, or whose tax has
     *     been adjusted by hand, is refused with a {@see ValidationException} whose
     *     `reason` says which, and no request leaves.
     *  3. After the write, every amount is compared with the read. If the provider
     *     moved any of them, {@see RecodeMovedMoneyException} carries both states;
     *     the write has landed and a person has to look.
     *
     * An empty change is refused with {@see InvalidPayloadException} before any
     * request. Whether a reconciled transaction can be recoded is still the
     * provider's decision; a refusal surfaces as a ValidationException with no
     * `reason`, exactly as any other provider rejection.
     *
     * `$idempotencyKey`, when given, is sent so a retried write after a lost response
     * is answered with the same result rather than applied twice.
     *
     * @throws NotFoundException when the transaction no longer exists
     * @throws PreconditionFailedException when the provider's record has changed since the decision
     * @throws ValidationException when the connector or the provider refuses the change
     * @throws RecodeMovedMoneyException when the provider accepted the change and moved an amount
     * @throws InvalidPayloadException when the change would change nothing
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function recodeBankTransaction(
        Connection $connection,
        string $externalId,
        BankTransactionChange $change,
        ?RecodeExpectation $expectation = null,
        ?string $idempotencyKey = null,
    ): RecodeResult;

    /**
     * Apply coding only, with no expectation, and return the transaction as it now stands.
     *
     * The original shape of this contract, kept for callers that have not adopted
     * {@see self::recodeBankTransaction()}. It has the same tax and moved-money
     * guarantees; what it lacks is the precondition, so it can land on coding a
     * person changed between the host's read and the write.
     *
     * @param  array<int, LineCoding>  $codings
     *
     * @throws NotFoundException when the transaction no longer exists
     * @throws ValidationException when the connector or the provider refuses the change
     * @throws RecodeMovedMoneyException when the provider accepted the change and moved an amount
     * @throws InvalidPayloadException when the codings would change nothing: none given, or none carrying an account code or tracking
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function updateBankTransactionCoding(
        Connection $connection,
        string $externalId,
        array $codings,
    ): BankTransactionData;

    /**
     * Delete a spend or receive money transaction the host created.
     *
     * Returns the transaction as the provider holds it afterwards, with a status
     * of DELETED; a transaction the provider no longer has at all comes back the
     * same way, because "already gone" is the outcome the host wanted. The host
     * proves the transaction is its own, live, authorised and unreconciled on a
     * fresh read before calling this; nothing here re-checks that.
     *
     * `$idempotencyKey`, when given, makes a retried delete after a lost response
     * answer the same way rather than fail on a transaction that is now gone.
     *
     * @throws ValidationException when the provider refuses, or accepts and does not delete
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function deleteBankTransaction(
        Connection $connection,
        string $externalId,
        ?string $idempotencyKey = null,
    ): BankTransactionData;
}
