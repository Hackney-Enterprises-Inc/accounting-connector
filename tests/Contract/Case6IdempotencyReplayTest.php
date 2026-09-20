<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 6. What the recode journal's retry dedupe rests on (T12).
 *
 * WAITS ON T8: XeroConnector::recodeBankTransaction() with an idempotency key.
 *
 * Xero's Idempotency-Key header is documented for creates; the journal protocol
 * sends one on every recode so a retried job cannot land twice. Whether Xero honours
 * it on an update is recorded here: two identical recodes under one key should come
 * back as one transaction with one UpdatedDateUTC.
 */
it('returns the same transaction for an idempotency-key replay of a recode (T12)', function (): void {
    $this->requireConnectorMethod('recodeBankTransaction', 'T8');

    $id = $this->createSpend(4567, 'case6');
    $fresh = $this->find($id);

    expect($fresh)->not->toBeNull();

    $target = $this->anotherExpenseAccountCode((string) $fresh->lines[0]->accountCode);
    $key = 'recode-'.self::runId().'-case6';
    $change = new BankTransactionChange([LineCoding::forAllLines($target)]);

    /** @phpstan-ignore-next-line T8 adds this method. */
    $first = $this->xero->recodeBankTransaction($this->connection, $id, $change, null, $key);

    /** @phpstan-ignore-next-line T8 adds this method. */
    $second = $this->xero->recodeBankTransaction($this->connection, $id, $change, null, $key);

    expect($second->after->id)->toBe($first->after->id)
        ->and($second->after->accountCodes())->toBe([$target]);

    $sameStamp = $first->after->updatedDateUtc?->format(DATE_ATOM) === $second->after->updatedDateUtc?->format(DATE_ATOM);

    $this->recordAnswer('case 6 idempotency replay', sprintf(
        'both calls returned %s coded %s; UpdatedDateUTC identical on replay: %s (first %s, second %s)',
        $id,
        $target,
        $sameStamp ? 'yes, the replay was served from the key' : 'NO, the second POST was applied as a new update',
        $first->after->updatedDateUtc?->format(DATE_ATOM) ?? 'null',
        $second->after->updatedDateUtc?->format(DATE_ATOM) ?? 'null',
    ));
});
