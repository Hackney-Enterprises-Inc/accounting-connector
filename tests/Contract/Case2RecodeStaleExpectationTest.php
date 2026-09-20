<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\RecodeExpectation;
use Hei\AccountingConnector\Exceptions\PreconditionFailedException;
use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 2. The precondition that keeps a recode off coding it was not decided on.
 *
 * WAITS ON T8: XeroConnector::recodeBankTransaction(), BankTransactionChange and
 * RecodeExpectation, plus PreconditionFailedException. The case skips, naming T8,
 * until they exist. Written against the plan's signatures (section 4, T8).
 *
 * The connector reads the transaction itself before it POSTs. When the caller's
 * expectation (the codes by line and the UpdatedDateUTC it decided against) does not
 * match that read, the connector must refuse locally: no POST at all. The recording
 * transport is the evidence.
 */
it('refuses a recode with a stale expectation locally, sending no POST (T8)', function (): void {
    $this->requireConnectorMethod('recodeBankTransaction', 'T8');

    $id = $this->createSpend(1234, 'case2');
    $fresh = $this->find($id);

    expect($fresh)->not->toBeNull();

    $other = $this->anotherExpenseAccountCode((string) $fresh->lines[0]->accountCode);

    // An expectation from a read that is no longer true: same codes, but an
    // UpdatedDateUTC from the last century.
    $stale = new RecodeExpectation(
        accountCodesByLine: [(string) $fresh->lines[0]->lineItemId => (string) $fresh->lines[0]->accountCode],
        updatedDateUtc: new DateTimeImmutable('2000-01-01T00:00:00Z'),
    );

    $postsBefore = $this->requests->count('POST');

    expect(fn () => $this->xero->recodeBankTransaction(
        $this->connection,
        $id,
        new BankTransactionChange([LineCoding::forAllLines($other)]),
        $stale,
    ))->toThrow(PreconditionFailedException::class);

    expect($this->requests->count('POST'))->toBe($postsBefore);

    $unchanged = $this->find($id);

    expect($unchanged?->accountCodes())->toBe($fresh->accountCodes());

    $this->recordAnswer('case 2 stale expectation', sprintf(
        'REFUSED LOCALLY: PreconditionFailedException, %d POST(s) sent, coding on %s unchanged',
        $this->requests->count('POST') - $postsBefore,
        $id,
    ));
});
