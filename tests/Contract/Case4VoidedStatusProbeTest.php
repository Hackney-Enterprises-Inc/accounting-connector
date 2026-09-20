<?php

declare(strict_types=1);

use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 4. A documented probe; nothing in the plan depends on its answer.
 *
 * The OpenAPI enum lists VOIDED for BankTransaction.Status, the status codes page
 * lists only AUTHORISED and DELETED for bank transactions, and the first draft of
 * the plan proposed voiding. This records what Xero actually says to a VOIDED status
 * on an ordinary SPEND so the question never has to be reopened.
 *
 * Sent raw: the package deliberately has no method for this.
 */
it('records what Xero returns for Status VOIDED on an ordinary SPEND (documented probe)', function (): void {
    $id = $this->createSpend(3456, 'case4');

    // The same envelope the package's own delete sends: Xero's POST takes the
    // BankTransactions array, and a bare object is the shape it documents as
    // "may be accepted", not the one it guarantees.
    $response = $this->raw('POST', 'BankTransactions/'.rawurlencode($id), body: [
        'BankTransactions' => [[
            'BankTransactionID' => $id,
            'Status' => 'VOIDED',
        ]],
    ]);

    $after = $this->find($id);

    $message = $response->get('Message') ?? $response->get('Elements.0.ValidationErrors.0.Message') ?? '';

    expect($response->status)->toBeGreaterThanOrEqual(200)
        ->and($response->status)->toBeLessThan(500);

    if ($after === null || strtoupper((string) $after->status) !== 'AUTHORISED') {
        // Whatever VOIDED did, the fixture is no longer an ordinary SPEND: a voided
        // line cannot be deleted by the DELETED request tearDown sends, and Xero
        // offers no other way to remove one, so it is left in the demo company and
        // its id is written into the answer so a person can find it.
        $this->createdTransactionIds = array_values(array_diff($this->createdTransactionIds, [$id]));
    }

    $this->recordAnswer('case 4 VOIDED probe', sprintf(
        'HTTP %d%s; transaction %s afterwards: %s%s',
        $response->status,
        is_string($message) && $message !== '' ? ' "'.$message.'"' : '',
        $id,
        $after === null ? 'gone (404)' : 'Status '.(string) $after->status,
        $after !== null && strtoupper((string) $after->status) !== 'AUTHORISED' ? ' (left in the demo company; not deletable)' : '',
    ));
});
