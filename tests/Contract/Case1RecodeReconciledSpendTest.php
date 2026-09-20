<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 1. The gate for T12 (holding accounts and the recode journal).
 *
 * Xero Central says a reconciled spend money can be edited as long as the line
 * amounts still add up; the API documentation says nothing either way. The whole
 * recode-on-match feature rests on the answer, so it is asked of Xero directly: take
 * a reconciled, single-line SPEND from the demo company's own history, move its line
 * to another expense account with every amount unchanged, and put it back.
 */
it('accepts a recode of a reconciled SPEND when the amounts are unchanged (gate for T12)', function (): void {
    $transaction = $this->findReconciledSingleLineSpend();

    if ($transaction === null) {
        $this->markTestSkipped('The demo company holds no reconciled, coded, single-line SPEND to recode.');
    }

    $original = (string) $transaction->lines[0]->accountCode;
    $target = $this->anotherExpenseAccountCode($original);

    try {
        $after = $this->recodeLines($transaction->id, [LineCoding::forAllLines($target)]);

        expect($after->accountCodes())->toBe([$target])
            ->and($after->total->amount)->toBe($transaction->total->amount)
            ->and($after->subTotal?->amount)->toBe($transaction->subTotal?->amount)
            ->and($after->totalTax?->amount)->toBe($transaction->totalTax?->amount);

        $this->recordAnswer('case 1 reconciled recode', sprintf(
            'ACCEPTED: %s recoded %s -> %s; Total %d unchanged; IsReconciled after: %s',
            $transaction->id,
            $original,
            $target,
            $after->total->amount,
            $after->isReconciled ? 'true' : 'false',
        ));
    } finally {
        // Whatever happened, the demo company's own transaction goes back the way it was.
        $this->recodeLines($transaction->id, [LineCoding::forAllLines($original)]);
    }
});
