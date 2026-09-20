<?php

declare(strict_types=1);

use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 7. Two numbers T3 and T8 need and the documentation disagrees with itself on.
 *
 * The BankTransactions page shows pageSize=250; the OpenAPI spec allows 1000.
 * Decision 19 walks at 250 until this case proves a higher value is accepted. And
 * unit amounts come back to two places unless unitdp=4 is sent, which T8 needs to
 * round-trip a 4dp unit price without moving the total.
 *
 * Sent raw: BankTransactionQuery gains pageSize and unitdp in T3 and T8; the probe
 * must not wait on them.
 */
it('records the largest accepted pageSize on BankTransactions and that unitdp=4 is accepted (T3, T8)', function (): void {
    $accepted = [];
    $refused = [];

    foreach ([250, 500, 1000] as $size) {
        $response = $this->raw('GET', 'BankTransactions', ['page' => 1, 'pageSize' => $size]);

        if ($response->successful()) {
            $rows = $response->get('BankTransactions', []);
            $accepted[$size] = is_array($rows) ? count($rows) : 0;
        } else {
            $message = $response->get('Message') ?? $response->get('Detail') ?? '';
            $refused[$size] = $response->status.(is_string($message) && $message !== '' ? ' "'.$message.'"' : '');
        }
    }

    expect($accepted)->toHaveKey(250, 'pageSize=250 is documented and must be accepted.');

    $largest = max(array_keys($accepted));

    $unitdp = $this->raw('GET', 'BankTransactions', ['page' => 1, 'unitdp' => 4]);
    $sample = $unitdp->get('BankTransactions.0.LineItems.0.UnitAmount');
    $fourPlaces = is_numeric($sample) && preg_match('/\.\d{4}$/', (string) $sample) === 1;

    expect($unitdp->successful())->toBeTrue('unitdp=4 was refused: HTTP '.$unitdp->status);

    $this->recordAnswer('case 7 pageSize and unitdp', sprintf(
        'largest accepted pageSize: %d (accepted: %s; refused: %s); unitdp=4: HTTP %d, first UnitAmount "%s" %s',
        $largest,
        implode(', ', array_map(static fn (int $size, int $count): string => "{$size} -> {$count} rows", array_keys($accepted), $accepted)),
        $refused === [] ? 'none' : implode(', ', array_map(static fn (int $size, string $why): string => "{$size} -> {$why}", array_keys($refused), $refused)),
        $unitdp->status,
        is_scalar($sample) ? (string) $sample : 'n/a',
        $fourPlaces ? 'has four decimal places' : 'does not show four decimal places (JSON may serialise as a number)',
    ));
});
