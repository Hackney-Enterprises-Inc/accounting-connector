<?php

declare(strict_types=1);

use Hei\AccountingConnector\Contracts\VoidsInvoices;
use Hei\AccountingConnector\Data\InvoiceState;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Exceptions\InvoiceHasPaymentsException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Testing\FakeConnector;

/**
 * A bill in the fake's books.
 */
function fakeInvoice(string $id = 'inv-1', string $status = 'AUTHORISED', int $paidCents = 0): InvoiceState
{
    return new InvoiceState(
        id: $id,
        status: $status,
        type: 'ACCPAY',
        invoiceNumber: 'BILL-1',
        total: Money::cents(7500),
        amountDue: Money::cents(7500 - $paidCents),
        amountPaid: Money::cents($paidCents),
        currency: 'USD',
        contactId: 'contact-1',
        contactName: 'Acme Supply',
        hasPayments: $paidCents > 0,
    );
}

it('is the optional contract, like the real connector', function () {
    expect(new FakeConnector)->toBeInstanceOf(VoidsInvoices::class);
});

it('finds a stocked invoice, records the lookup, and answers null for one it never held', function () {
    $fake = (new FakeConnector)->withInvoices(fakeInvoice());

    expect($fake->findInvoice(connection(), 'inv-1')?->status)->toBe('AUTHORISED')
        ->and($fake->findInvoice(connection(), 'inv-9'))->toBeNull()
        ->and($fake->invoiceLookups)->toBe(['inv-1', 'inv-9']);
});

it('voids an approved bill, keeps it for a later find, and records the key', function () {
    $fake = (new FakeConnector)->withInvoices(fakeInvoice());

    $after = $fake->voidInvoice(connection(), 'inv-1', 'void-op-1');

    expect($after->status)->toBe('VOIDED')
        ->and($after->isVoided())->toBeTrue()
        ->and($after->amountDue?->amount)->toBe(0)
        ->and($after->total?->amount)->toBe(7500)
        ->and($fake->findInvoice(connection(), 'inv-1')?->status)->toBe('VOIDED')
        ->and($fake->voided)->toBe([['invoice_id' => 'inv-1', 'idempotency_key' => 'void-op-1']]);

    // Voiding again is the same answer and no second record.
    expect($fake->voidInvoice(connection(), 'inv-1')->status)->toBe('VOIDED')
        ->and($fake->voided)->toHaveCount(1);
});

it('deletes a draft, as Xero would', function () {
    $fake = (new FakeConnector)->withInvoices(fakeInvoice(status: 'DRAFT'));

    expect($fake->voidInvoice(connection(), 'inv-1')->status)->toBe('DELETED');
});

it('treats an invoice it never held as voided by id, like a 404', function () {
    $fake = new FakeConnector;

    $gone = $fake->voidInvoice(connection(), 'never-there');

    expect($gone->id)->toBe('never-there')
        ->and($gone->isVoided())->toBeTrue()
        ->and($fake->voided)->toBe([['invoice_id' => 'never-there', 'idempotency_key' => null]]);
});

it('refuses to void a bill with money applied and changes nothing', function () {
    $fake = (new FakeConnector)->withInvoices(fakeInvoice(paidCents: 2500));

    expect(fn () => $fake->voidInvoice(connection(), 'inv-1'))
        ->toThrow(InvoiceHasPaymentsException::class, 'Remove the payment');

    expect($fake->voided)->toBeEmpty()
        ->and($fake->findInvoice(connection(), 'inv-1')?->status)->toBe('AUTHORISED');
});

it('fails the next invoice call once, then answers again', function () {
    $fake = (new FakeConnector)
        ->withInvoices(fakeInvoice())
        ->failNextInvoiceCall(new ServerException('Xero is down'));

    expect(fn () => $fake->voidInvoice(connection(), 'inv-1'))->toThrow(ServerException::class);

    expect($fake->voided)->toBeEmpty()
        ->and($fake->findInvoice(connection(), 'inv-1')?->status)->toBe('AUTHORISED');
});

it('forgets invoices, voids, lookups and the queued failure on flush', function () {
    $fake = (new FakeConnector)
        ->withInvoices(fakeInvoice())
        ->failNextInvoiceCall(new ServerException('Xero is down'));

    $fake->flush();

    expect($fake->invoices)->toBeEmpty()
        ->and($fake->voided)->toBeEmpty()
        ->and($fake->invoiceLookups)->toBeEmpty()
        ->and($fake->findInvoice(connection(), 'inv-1'))->toBeNull();
});
