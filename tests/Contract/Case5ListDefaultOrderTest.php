<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 5. What T3's resumable walk rests on.
 *
 * The BankTransactions page says the default order is UpdatedDateUTC ASC then
 * BankTransactionID ASC, "consistent across pages". Two pages are read with no order
 * parameter and the primary key is asserted non-decreasing across the boundary. The
 * secondary key is recorded rather than asserted: "BankTransactionID ASC" says
 * nothing about whether that is a lexicographic order of the GUID text, and the
 * mirror must not assume it.
 */
it('lists in UpdatedDateUTC ASC then BankTransactionID ASC order across two pages by default (T3)', function (): void {
    $first = $this->xero->listBankTransactions($this->connection, new BankTransactionQuery(type: BankTransactionType::Spend));

    if (! $first->hasMore()) {
        $this->markTestSkipped('The demo company holds fewer than '.(BankTransactionQuery::PAGE_SIZE + 1).' SPEND transactions, so there is no page boundary to test.');
    }

    $second = $this->xero->listBankTransactions(
        $this->connection,
        (new BankTransactionQuery(type: BankTransactionType::Spend))->nextPage(),
    );

    /** @var array<int, BankTransactionData> $rows */
    $rows = array_merge($first->transactions, $second->transactions);

    $primaryOrdered = true;
    $tiesLexicographic = true;
    $ties = 0;

    for ($i = 1, $n = count($rows); $i < $n; $i++) {
        $previous = $rows[$i - 1];
        $current = $rows[$i];

        $previousStamp = $previous->updatedDateUtc?->getTimestamp() ?? 0;
        $currentStamp = $current->updatedDateUtc?->getTimestamp() ?? 0;

        if ($currentStamp < $previousStamp) {
            $primaryOrdered = false;
        }

        if ($currentStamp === $previousStamp) {
            $ties++;

            if (strcmp(strtolower($current->id), strtolower($previous->id)) < 0) {
                $tiesLexicographic = false;
            }
        }
    }

    $boundaryStamp = $second->transactions[0]->updatedDateUtc?->getTimestamp() ?? 0;
    $lastOfFirst = $first->transactions[count($first->transactions) - 1]->updatedDateUtc?->getTimestamp() ?? 0;

    expect($primaryOrdered)->toBeTrue('UpdatedDateUTC went backwards within or across the two pages.')
        ->and($boundaryStamp)->toBeGreaterThanOrEqual($lastOfFirst);

    $this->recordAnswer('case 5 default order', sprintf(
        'UpdatedDateUTC non-decreasing across %d rows and the page boundary: %s; %d equal-timestamp pairs, id order lexicographic on those: %s',
        count($rows),
        $primaryOrdered ? 'yes' : 'NO',
        $ties,
        $ties === 0 ? 'n/a' : ($tiesLexicographic ? 'yes' : 'NO (GUID order is not text order)'),
    ));
});
