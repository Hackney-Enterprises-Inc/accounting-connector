<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 3. The gate for T10 (delete and hold again).
 *
 * WAITS ON T10: XeroConnector::deleteBankTransaction(). Skips, naming T10, until it
 * exists.
 *
 * Xero documents the delete (POST /BankTransactions/{id} with Status DELETED) but not
 * what a GET returns afterwards (404, or the row with Status DELETED) nor whether an
 * unfiltered list still returns the row. T3's mirror and T10's reversal both need
 * the answer, so it is recorded rather than assumed.
 */
it('deletes an unreconciled SPEND via Status DELETED and records what a GET and a list return afterwards (gate for T10)', function (): void {
    $this->requireConnectorMethod('deleteBankTransaction', 'T10');

    $id = $this->createSpend(2345, 'case3');

    /** @phpstan-ignore-next-line T10 adds this method. */
    $this->xero->deleteBankTransaction($this->connection, $id);

    // The fixture is gone, or on its way; tearDown must not try again.
    $this->createdTransactionIds = array_values(array_diff($this->createdTransactionIds, [$id]));

    $after = $this->find($id);
    $getAnswer = $after === null ? 'GET is 404 (null)' : 'GET returns the row with Status '.(string) $after->status;

    $listed = false;
    $query = new BankTransactionQuery(
        type: BankTransactionType::Spend,
        modifiedSince: new DateTimeImmutable('-10 minutes'),
    );

    for ($page = 0; $page < 3; $page++) {
        $result = $this->xero->listBankTransactions($this->connection, $query);

        foreach ($result->transactions as $transaction) {
            if ($transaction->id === $id) {
                $listed = true;
                $listAnswer = 'unfiltered list (no Status clause) STILL RETURNS the row with Status '.(string) $transaction->status;
            }
        }

        if ($listed || ! $result->hasMore()) {
            break;
        }

        $query = $query->nextPage();
    }

    $listAnswer ??= 'unfiltered list (no Status clause) does NOT return the deleted row';

    expect($after === null || strtoupper((string) $after->status) === 'DELETED')->toBeTrue();

    $this->recordAnswer('case 3 delete', "ACCEPTED: {$id}; {$getAnswer}; {$listAnswer}");
});
