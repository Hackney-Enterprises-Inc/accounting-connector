<?php

declare(strict_types=1);

use Hei\AccountingConnector\Contracts\VoidsInvoices;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\InvoiceHasPaymentsException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Exceptions\ValidationException;

it('implements the optional contract a host asks for with instanceof', function () {
    expect(xeroReader(fakeHttp()))->toBeInstanceOf(VoidsInvoices::class);
});

it('reads an invoice off the wire into its state', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-authorised'));

    $state = xeroReader($fake)->findInvoice(connection(), 'invoice-1');

    expect($state)->not->toBeNull()
        ->and($state?->id)->toBe('invoice-1')
        ->and($state?->status)->toBe('AUTHORISED')
        ->and($state?->type)->toBe('ACCPAY')
        ->and($state?->invoiceNumber)->toBe('BILL-0042')
        ->and($state?->reference)->toBe('PO-7')
        ->and($state?->date?->format('Y-m-d'))->toBe('2025-08-21')
        ->and($state?->total?->amount)->toBe(7500)
        ->and($state?->amountDue?->amount)->toBe(7500)
        ->and($state?->amountPaid?->amount)->toBe(0)
        ->and($state?->currency)->toBe('USD')
        ->and($state?->contactId)->toBe('contact-1')
        ->and($state?->contactName)->toBe('Acme Supply')
        ->and($state?->hasPayments)->toBeFalse()
        ->and($state?->isModifiable())->toBeTrue()
        ->and($state?->isVoided())->toBeFalse()
        ->and($state?->isPaid())->toBeFalse()
        ->and($fake->requests[0]->getMethod())->toBe('GET')
        ->and($fake->requests[0]->getUri()->getPath())->toBe('/api.xro/2.0/Invoices/invoice-1')
        ->and($fake->requests[0]->getHeaderLine('Accept'))->toBe('application/json');
});

it('answers null for an invoice Xero no longer has', function () {
    $fake = fakeHttp();
    $fake->queue(404, ['Message' => 'The resource you\'re looking for cannot be found']);

    expect(xeroReader($fake)->findInvoice(connection(), 'gone'))->toBeNull();
});

it('reports a paid invoice as paid, not modifiable, with money applied', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-paid'));

    $state = xeroReader($fake)->findInvoice(connection(), 'invoice-1');

    expect($state?->isPaid())->toBeTrue()
        ->and($state?->isModifiable())->toBeFalse()
        ->and($state?->hasPayments)->toBeTrue()
        ->and($state?->amountPaid?->amount)->toBe(7500)
        ->and($state?->amountDue?->amount)->toBe(0);
});

it('refuses a blank id before any request', function () {
    $fake = fakeHttp();

    expect(fn () => xeroReader($fake)->findInvoice(connection(), ' '))->toThrow(InvalidPayloadException::class)
        ->and(fn () => xeroReader($fake)->voidInvoice(connection(), ''))->toThrow(InvalidPayloadException::class)
        ->and($fake->requests)->toBeEmpty();
});

it('voids an approved bill: reads, posts VOIDED, reads again and returns what Xero holds', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-authorised'));
    $fake->queue(200, providerResponse('xero/invoice-voided'));
    $fake->queue(200, providerResponse('xero/invoice-voided'));

    $after = xeroReader($fake)->voidInvoice(connection(), 'invoice-1', 'void-op-1');

    $body = $fake->requestBody(1)['Invoices'][0];

    expect($after->status)->toBe('VOIDED')
        ->and($after->isVoided())->toBeTrue()
        ->and($after->total?->amount)->toBe(7500)
        ->and($fake->requests)->toHaveCount(3)
        ->and($fake->requests[0]->getMethod())->toBe('GET')
        ->and($fake->requests[1]->getMethod())->toBe('POST')
        ->and($fake->requests[1]->getUri()->getPath())->toBe('/api.xro/2.0/Invoices/invoice-1')
        ->and($fake->requests[1]->getHeaderLine('Idempotency-Key'))->toBe('void-op-1')
        ->and($body)->toBe(['InvoiceID' => 'invoice-1', 'Status' => 'VOIDED'])
        ->and($fake->requests[2]->getMethod())->toBe('GET');
});

it('deletes rather than voids a draft, the only status Xero accepts for one', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-created'));
    $fake->queue(200, providerResponse('xero/invoice-deleted'));
    $fake->queue(200, providerResponse('xero/invoice-deleted'));

    $after = xeroReader($fake)->voidInvoice(connection(), 'invoice-1');

    expect($fake->requestBody(1)['Invoices'][0]['Status'])->toBe('DELETED')
        ->and($after->status)->toBe('DELETED')
        ->and($after->isVoided())->toBeTrue()
        ->and($fake->requests[1]->hasHeader('Idempotency-Key'))->toBeFalse();
});

it('returns an invoice already voided without writing', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-voided'));

    $after = xeroReader($fake)->voidInvoice(connection(), 'invoice-1');

    expect($after->status)->toBe('VOIDED')
        ->and($fake->requests)->toHaveCount(1);
});

it('treats an invoice Xero no longer has as voided, on the read and on the write', function () {
    $fake = fakeHttp();
    $fake->queue(404, ['Message' => 'not found']);

    $gone = xeroReader($fake)->voidInvoice(connection(), 'gone');

    expect($gone->id)->toBe('gone')
        ->and($gone->status)->toBe('VOIDED')
        ->and($gone->isVoided())->toBeTrue()
        ->and($fake->requests)->toHaveCount(1);

    // Read fine, gone by the time the POST lands: the same answer.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-authorised'));
    $fake->queue(404, ['Message' => 'not found']);

    expect(xeroReader($fake)->voidInvoice(connection(), 'invoice-1')->isVoided())->toBeTrue();
});

it('refuses before writing when the read shows money applied, naming the amount', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-part-paid'));

    try {
        xeroReader($fake)->voidInvoice(connection(), 'invoice-1');
        $this->fail('expected InvoiceHasPaymentsException');
    } catch (InvoiceHasPaymentsException $e) {
        expect($e->getMessage())->toContain('25.00 USD paid')
            ->and($e->getMessage())->toContain('Remove the payment')
            ->and($e->state?->amountPaid?->amount)->toBe(2500)
            ->and($e->providerMessage)->toBeNull();
    }

    expect($fake->requests)->toHaveCount(1);
});

it('turns a refusal for applied money after the write into the same typed exception, carrying Xero\'s words', function () {
    // The race the read cannot close: a payment applied between the read and the POST.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-authorised'));
    $fake->queue(400, providerResponse('xero/invoice-void-refused-payments'));

    try {
        xeroReader($fake)->voidInvoice(connection(), 'invoice-1');
        $this->fail('expected InvoiceHasPaymentsException');
    } catch (InvoiceHasPaymentsException $e) {
        expect($e->providerMessage)->toBe('Invoice cannot be voided as it has payments allocated to it')
            ->and($e->getMessage())->toContain('payments allocated')
            ->and($e->state?->status)->toBe('AUTHORISED');
    }
});

it('leaves every other refusal as a ValidationException with Xero\'s message', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-authorised'));
    $fake->queue(400, providerResponse('xero/invoice-not-modifiable'));

    try {
        xeroReader($fake)->voidInvoice(connection(), 'invoice-1');
        $this->fail('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->providerMessage)->toBe('Invoice not of valid status for modification')
            ->and($e->errors)->toBe(['Invoice not of valid status for modification']);
    }
});

it('passes a server failure through as the retryable it is', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-authorised'));
    $fake->queue(500, ['Message' => 'boom']);

    expect(fn () => xeroReader($fake)->voidInvoice(connection(), 'invoice-1'))->toThrow(ServerException::class);
});

it('refuses to report a void the re-read does not confirm', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-authorised'));
    $fake->queue(200, providerResponse('xero/invoice-voided'));
    // The read after says AUTHORISED: the write was accepted and changed nothing.
    $fake->queue(200, providerResponse('xero/invoice-authorised'));

    expect(fn () => xeroReader($fake)->voidInvoice(connection(), 'invoice-1'))
        ->toThrow(ValidationException::class, 'still reports it as AUTHORISED');
});
