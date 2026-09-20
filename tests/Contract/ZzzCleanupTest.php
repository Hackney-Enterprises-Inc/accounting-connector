<?php

declare(strict_types=1);

use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * The sweep. Named to sort last so it runs after every case in the directory.
 *
 * Each case removes its own fixtures in tearDown; this catches what an aborted run
 * or an earlier run without deleteBankTransaction (T10) left behind. It searches by
 * the reference prefix every fixture carries, across runs, and deletes what it finds.
 * Until T10 lands it can only list them.
 */
it('sweeps every AP-CONTRACT fixture left in the demo company', function (): void {
    $found = [];

    for ($page = 1; $page <= 5; $page++) {
        $response = $this->raw('GET', 'BankTransactions', [
            'page' => $page,
            'where' => 'Reference.StartsWith("'.ContractCase::REFERENCE_PREFIX.'")&&Status=="AUTHORISED"',
        ]);

        expect($response->successful())->toBeTrue('The sweep list failed: HTTP '.$response->status);

        $rows = $response->get('BankTransactions', []);

        if (! is_array($rows) || $rows === []) {
            break;
        }

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['BankTransactionID'] ?? null)) {
                $found[] = $row['BankTransactionID'];
            }
        }

        if (count($rows) < 100) {
            break;
        }
    }

    if ($found === []) {
        $this->recordAnswer('cleanup', 'nothing left behind');

        return;
    }

    if (! method_exists($this->xero, 'deleteBankTransaction')) {
        $this->recordAnswer('cleanup', count($found).' fixture(s) left behind until T10 adds deleteBankTransaction: '.implode(', ', $found));

        return;
    }

    $deleted = 0;

    foreach ($found as $id) {
        /** @phpstan-ignore-next-line T10 adds this method. */
        $this->xero->deleteBankTransaction($this->connection, $id);
        $deleted++;
    }

    $this->recordAnswer('cleanup', "deleted {$deleted} fixture(s): ".implode(', ', $found));

    expect($deleted)->toBe(count($found));
});
