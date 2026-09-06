<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionPage;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;

/**
 * Reading the spend a provider already knows about.
 *
 * Separate from {@see AccountingConnector} because it is genuinely optional: a
 * connector for a provider with no bank feed concept should not have to implement
 * two methods that can only throw. A host asks with `instanceof` and falls back to
 * posting when the answer is no.
 *
 * The reason this exists at all: a customer whose bank feed is connected already has
 * the transaction in their books before the receipt reaches us. Posting a second one
 * is a duplicate they then have to unpick by hand, and the only way to avoid it is to
 * be able to look.
 *
 * NOTHING HERE IS A SOURCE OF TRUTH. A host will mirror these rows locally to make
 * matching affordable, and the mirror will be stale: transactions get deleted,
 * recoded and reconciled between syncs. Re-read the single transaction with
 * {@see self::findBankTransaction()} before acting on a match.
 */
interface ReadsBankTransactions
{
    /**
     * One page of bank transactions matching the query.
     *
     * Ordered by the provider, which for Xero means no guaranteed order at all, so a
     * host that pages must page to the end rather than stopping at a date it
     * recognises.
     *
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function listBankTransactions(Connection $connection, BankTransactionQuery $query): BankTransactionPage;

    /**
     * One bank transaction as the provider holds it right now.
     *
     * Null when the provider has no such transaction, which includes the case that
     * matters most: a mirrored row for a transaction the customer has since deleted.
     * A host must treat that as "do not match", not as an error.
     *
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function findBankTransaction(Connection $connection, string $externalId): ?BankTransactionData;
}
