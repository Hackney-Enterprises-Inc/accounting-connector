<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\TrackingRef;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Exceptions\NotFoundException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Testing\FakeHttpClient;

function xeroReader(FakeHttpClient $fake): XeroConnector
{
    return new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
    );
}

/**
 * The query string of a recorded request, decoded.
 *
 * @return array<string, string>
 */
function queryOf(FakeHttpClient $fake, int $index): array
{
    parse_str((string) $fake->requests[$index]->getUri()->getQuery(), $parsed);

    /** @var array<string, string> $parsed */
    return $parsed;
}

it('reads a page of bank transactions off the wire', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/bank-transactions'));

    $page = xeroReader($fake)->listBankTransactions(connection(), new BankTransactionQuery);

    expect($page->count())->toBe(2)
        ->and($page->hasMore())->toBeFalse();

    $first = $page->transactions[0];

    expect($first->id)->toBe('spend-uuid-1')
        ->and($first->type)->toBe(BankTransactionType::Spend)
        // Microsoft JSON dates, not ISO. Parsed as the string it literally is, every
        // transaction lands in 1970 and every date-window match silently misses.
        ->and($first->date?->format('Y-m-d'))->toBe('2026-08-28')
        ->and($first->total->amount)->toBe(4250)
        ->and($first->currency)->toBe('USD')
        ->and($first->contactName)->toBe('Acme Supply')
        ->and($first->bankAccountId)->toBe('bank-uuid')
        ->and($first->reference)->toBe('VISA 4242')
        ->and($first->isReconciled)->toBeFalse()
        ->and($first->hasAttachments)->toBeFalse()
        ->and($first->isCoded())->toBeFalse();
});

it('reads tax, coding and tracking off a transaction that has them', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/bank-transactions'));

    $page = xeroReader($fake)->listBankTransactions(connection(), new BankTransactionQuery);
    $second = $page->transactions[1];

    expect($second->total->amount)->toBe(9200)
        ->and($second->subTotal?->amount)->toBe(8000)
        ->and($second->totalTax?->amount)->toBe(1200)
        ->and($second->isReconciled)->toBeTrue()
        ->and($second->hasAttachments)->toBeTrue()
        ->and($second->isCoded())->toBeTrue()
        ->and($second->accountCodes())->toBe(['429'])
        ->and($second->lines[0]->lineItemId)->toBe('line-uuid-2')
        ->and($second->lines[0]->taxType)->toBe('INPUT2')
        ->and($second->lines[0]->tracking[0]->categoryId)->toBe('cat-uuid')
        ->and($second->lines[0]->tracking[0]->optionName)->toBe('North')
        // An empty Reference is nothing, not the empty string, so a matcher does not
        // score a vendor against "".
        ->and($second->reference)->toBeNull();
});

it('narrows the request at Xero rather than in the host', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['BankTransactions' => []]);

    xeroReader($fake)->listBankTransactions(connection(), new BankTransactionQuery(
        type: BankTransactionType::Spend,
        from: new DateTimeImmutable('2026-08-01'),
        to: new DateTimeImmutable('2026-08-31'),
        bankAccountId: 'bank-uuid',
        status: 'AUTHORISED',
        page: 3,
    ));

    $query = queryOf($fake, 0);

    expect($fake->requests[0]->getUri()->getPath())->toBe('/api.xro/2.0/BankTransactions')
        ->and($query['page'])->toBe('3')
        ->and($query['where'])->toBe(
            'Type=="SPEND"&&Status=="AUTHORISED"'
            .'&&Date>=DateTime(2026,8,1)&&Date<=DateTime(2026,8,31)'
            .'&&BankAccount.AccountID==Guid("bank-uuid")'
        );
});

it('sends the modified-since instant as a UTC header rather than a filter', function () {
    // Xero compares this against UpdatedDateUTC. Sent in a positive local offset it
    // asks for the future and a nightly sync quietly returns nothing forever.
    $fake = fakeHttp();
    $fake->queue(200, ['BankTransactions' => []]);

    xeroReader($fake)->listBankTransactions(connection(), new BankTransactionQuery(
        modifiedSince: new DateTimeImmutable('2026-08-28 09:30:00', new DateTimeZone('+10:00')),
    ));

    expect($fake->requests[0]->getHeaderLine('If-Modified-Since'))->toBe('2026-08-27T23:30:00')
        // Not part of the filter: it is compared against UpdatedDateUTC, which no
        // `where` clause on this endpoint can reach.
        ->and(queryOf($fake, 0)['where'])->toBe('Type=="SPEND"');
});

it('treats a 304 as an empty page rather than a failure', function () {
    $fake = fakeHttp();
    $fake->queue(304, []);

    $page = xeroReader($fake)->listBankTransactions(connection(), new BankTransactionQuery(
        modifiedSince: new DateTimeImmutable('2026-08-28'),
    ));

    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMore())->toBeFalse();
});

it('reports a full page as having more', function () {
    $rows = [];

    for ($i = 0; $i < BankTransactionQuery::PAGE_SIZE; $i++) {
        $rows[] = ['BankTransactionID' => 'spend-'.$i, 'Type' => 'SPEND', 'Total' => 1.0];
    }

    $fake = fakeHttp();
    $fake->queue(200, ['BankTransactions' => $rows]);

    $page = xeroReader($fake)->listBankTransactions(connection(), new BankTransactionQuery);

    expect($page->hasMore())->toBeTrue()
        ->and($page->count())->toBe(BankTransactionQuery::PAGE_SIZE);
});

it('reads one bank transaction by id', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/bank-transactions'));

    $transaction = xeroReader($fake)->findBankTransaction(connection(), 'spend-uuid-1');

    expect($transaction?->id)->toBe('spend-uuid-1')
        ->and($fake->requests[0]->getUri()->getPath())->toBe('/api.xro/2.0/BankTransactions/spend-uuid-1');
});

it('answers null for a transaction the customer has deleted', function () {
    // The case a mirror table produces constantly: a row we synced last night for a
    // transaction that is gone this morning. That is an answer, not an error.
    $fake = fakeHttp();
    $fake->queue(404, ['Message' => 'The resource you\'re looking for cannot be found']);

    expect(xeroReader($fake)->findBankTransaction(connection(), 'gone-uuid'))->toBeNull();
});

it('recodes a bank transaction without moving anything else on it', function () {
    $fake = fakeHttp();
    // The read the update does first, then the replacement Xero echoes back.
    $fake->queue(200, providerResponse('xero/bank-transactions'));
    $fake->queue(200, providerResponse('xero/bank-transactions'));

    xeroReader($fake)->updateBankTransactionCoding(connection(), 'spend-uuid-1', [
        LineCoding::forAllLines('429', [new TrackingRef('cat-uuid', 'opt-uuid')]),
    ]);

    $body = $fake->requestBody(1)['BankTransactions'][0];

    expect($fake->requests[1]->getMethod())->toBe('POST')
        ->and($body['BankTransactionID'])->toBe('spend-uuid-1')
        ->and($body['Type'])->toBe('SPEND')
        // The bank's own facts go back exactly as they came. A POST replaces the
        // transaction, so a field left out is a field cleared.
        ->and($body['BankAccount'])->toBe(['AccountID' => 'bank-uuid'])
        ->and($body['Contact'])->toBe(['ContactID' => 'contact-uuid-1'])
        ->and($body['Date'])->toBe('2026-08-28')
        ->and($body['Reference'])->toBe('VISA 4242')
        ->and($body['Status'])->toBe('AUTHORISED')
        ->and($body['CurrencyCode'])->toBe('USD');

    $line = $body['LineItems'][0];

    expect($line['LineItemID'])->toBe('line-uuid-1')
        ->and($line['AccountCode'])->toBe('429')
        ->and($line['UnitAmount'])->toBe(42.5)
        ->and($line['LineAmount'])->toBe(42.5)
        // PHP encodes a whole float as a JSON integer, so 1.0 goes over the wire
        // as 1. JSON has one number type and Xero parses either the same way.
        ->and($line['Quantity'])->toEqual(1.0)
        ->and($line['Tracking'])->toBe([[
            'TrackingCategoryID' => 'cat-uuid',
            'TrackingOptionID' => 'opt-uuid',
        ]]);
});

it('leaves lines the coding does not name alone', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['BankTransactions' => [[
        'BankTransactionID' => 'spend-uuid-3',
        'Type' => 'SPEND',
        'Total' => 30.0,
        'LineItems' => [
            ['LineItemID' => 'line-a', 'UnitAmount' => 10.0, 'AccountCode' => '400'],
            ['LineItemID' => 'line-b', 'UnitAmount' => 20.0, 'AccountCode' => '401'],
        ],
    ]]]);
    $fake->queue(200, ['BankTransactions' => [['BankTransactionID' => 'spend-uuid-3', 'Type' => 'SPEND', 'Total' => 30.0]]]);

    xeroReader($fake)->updateBankTransactionCoding(connection(), 'spend-uuid-3', [
        LineCoding::forLine('line-b', '429'),
    ]);

    $lines = $fake->requestBody(1)['BankTransactions'][0]['LineItems'];

    expect($lines[0]['AccountCode'])->toBe('400')
        ->and($lines[1]['AccountCode'])->toBe('429');
});

it('refuses to recode a transaction that is no longer there', function () {
    $fake = fakeHttp();
    $fake->queue(404, ['Message' => 'not found']);

    xeroReader($fake)->updateBankTransactionCoding(connection(), 'gone-uuid', [
        LineCoding::forAllLines('429'),
    ]);
})->throws(NotFoundException::class);

it('surfaces a refusal to recode rather than pre-empting it', function () {
    // Decision 4 of the matching plan: Xero's spec documents IsReconciled as a read
    // flag and states no restriction on updating a reconciled transaction, so the
    // call is made and a refusal is reported for the host to record.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/bank-transactions'));
    $fake->queue(400, providerResponse('xero/validation-error'));

    xeroReader($fake)->updateBankTransactionCoding(connection(), 'spend-uuid-2', [
        LineCoding::forAllLines('429'),
    ]);
})->throws(ValidationException::class);
