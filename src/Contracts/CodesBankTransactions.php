<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\NotFoundException;
use Hei\AccountingConnector\Exceptions\ValidationException;

/**
 * Changing how an existing bank transaction is coded, and nothing else about it.
 *
 * The narrow write that pairs with {@see ReadsBankTransactions}. Once a host has
 * decided a receipt belongs to a transaction the bank feed already created, the only
 * thing it has any business changing is the account code and tracking: the amount,
 * date, contact and bank account came from the bank and are not ours to edit.
 *
 * Xero has no partial update - a POST replaces the transaction - so the
 * implementation has to read the current state and send it back with the coding
 * changed. That read-modify-write is the whole reason this is a method here rather
 * than something a host assembles from updateEntity().
 */
interface CodesBankTransactions
{
    /**
     * Apply coding to an existing bank transaction and return it as it now stands.
     *
     * Amounts, dates, contact, bank account and reference are read from the provider
     * and sent back unchanged. Only AccountCode and tracking move.
     *
     * Whether a reconciled transaction can be recoded is the provider's decision, not
     * this method's: Xero's spec documents IsReconciled as a read flag and states no
     * restriction on updating a reconciled transaction, so the call is made and a
     * refusal surfaces as a ValidationException the host can record rather than being
     * pre-empted by a guess here. Callers that must not risk it should check
     * {@see BankTransactionData::$isReconciled} first.
     *
     * @param  array<int, LineCoding>  $codings
     *
     * @throws NotFoundException when the transaction no longer exists
     * @throws ValidationException when the provider refuses the change
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function updateBankTransactionCoding(
        Connection $connection,
        string $externalId,
        array $codings,
    ): BankTransactionData;
}
