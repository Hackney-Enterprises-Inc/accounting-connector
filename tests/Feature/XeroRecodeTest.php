<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\RecodeExpectation;
use Hei\AccountingConnector\Data\TrackingRef;
use Hei\AccountingConnector\Enums\LineAmountType;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\PreconditionFailedException;
use Hei\AccountingConnector\Exceptions\RecodeMovedMoneyException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Testing\FakeHttpClient;

/**
 * One transaction from the fixture, as a single-element response body.
 *
 * @return array<string, mixed>
 */
function transactionResponse(string $id, array $overrides = [], array $lineOverrides = []): array
{
    foreach (providerResponse('xero/bank-transactions')['BankTransactions'] as $row) {
        if ($row['BankTransactionID'] === $id) {
            $row = array_merge($row, $overrides);

            foreach ($lineOverrides as $index => $line) {
                $row['LineItems'][$index] = array_merge($row['LineItems'][$index], $line);
            }

            return ['BankTransactions' => [$row]];
        }
    }

    throw new RuntimeException("No fixture transaction {$id}.");
}

/**
 * The methods of every request the fake saw, in order.
 *
 * @return array<int, string>
 */
function methodsOf(FakeHttpClient $fake): array
{
    return array_map(fn ($request): string => $request->getMethod(), $fake->requests);
}

it('sends the tax mode back on an exclusive-tax transaction, so its total stays where it was', function () {
    // spend-uuid-2 is Exclusive: 80.00 plus 12.00 tax, total 92.00. Xero reads an
    // omitted LineAmountTypes as Inclusive, which would make the same 80.00 line a
    // total of 80.00 with 10.43 of tax inside it. The bank paid 92.00.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2'));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, transactionResponse('spend-uuid-2', lineOverrides: [['AccountCode' => '400']]));

    $result = xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400'));

    $body = $fake->requestBody(2)['BankTransactions'][0];

    expect(methodsOf($fake))->toBe(['GET', 'GET', 'POST'])
        ->and($body['LineAmountTypes'])->toBe('Exclusive')
        ->and($body['LineItems'][0]['LineAmount'])->toEqual(80.0)
        ->and($body['LineItems'][0]['TaxType'])->toBe('INPUT2')
        ->and($body['LineItems'][0])->not->toHaveKey('TaxAmount')
        ->and($body['LineItems'][0]['AccountCode'])->toBe('400')
        ->and($result->before->accountCodes())->toBe(['429'])
        ->and($result->after->accountCodes())->toBe(['400'])
        ->and($result->changedCoding())->toBeTrue()
        ->and($result->before->lineAmountType)->toBe(LineAmountType::Exclusive);
});

it('sends inclusive and no-tax transactions back in their own mode', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['LineAmountTypes' => 'Inclusive', 'SubTotal' => 69.57, 'TotalTax' => 10.43, 'Total' => 80.0], [['TaxAmount' => 10.43]]));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, transactionResponse('spend-uuid-2', ['LineAmountTypes' => 'Inclusive', 'SubTotal' => 69.57, 'TotalTax' => 10.43, 'Total' => 80.0], [['TaxAmount' => 10.43, 'AccountCode' => '400']]));
    $fake->queue(200, transactionResponse('spend-uuid-1', ['LineAmountTypes' => 'NoTax']));
    $fake->queue(200, transactionResponse('spend-uuid-1', ['LineAmountTypes' => 'NoTax'], [['AccountCode' => '400']]));

    $reader = xeroReader($fake);

    $reader->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400'));
    $reader->recodeBankTransaction(connection(), 'spend-uuid-1', BankTransactionChange::allLines('400'));

    expect($fake->requestBody(2)['BankTransactions'][0]['LineAmountTypes'])->toBe('Inclusive')
        ->and($fake->requestBody(4)['BankTransactions'][0]['LineAmountTypes'])->toBe('NoTax');
});

it('refuses a transaction whose tax mode the provider did not say, before any write', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1', ['LineAmountTypes' => null]));

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', BankTransactionChange::allLines('400')))
        ->toThrow(function (ValidationException $e): void {
            expect($e->reason)->toBe(ValidationException::REASON_TAX_MODE_UNKNOWN);
        });

    expect(methodsOf($fake))->toBe(['GET']);
});

it('refuses a line whose tax was adjusted by hand, because Xero would recompute it', function () {
    // 80.00 at INPUT2 (15 percent) is 12.00 of tax. A bookkeeper who typed 11.50
    // over it made a decision the endpoint cannot carry back: it ignores TaxAmount.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['TotalTax' => 11.5, 'Total' => 91.5], [['TaxAmount' => 11.5]]));
    $fake->queue(200, providerResponse('xero/tax-rates'));

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400')))
        ->toThrow(function (ValidationException $e): void {
            expect($e->reason)->toBe(ValidationException::REASON_TAX_OVERRIDE_WOULD_BE_LOST)
                ->and($e->getMessage())->toContain('11.50')
                ->and($e->getMessage())->toContain('12.00');
        });

    expect(methodsOf($fake))->toBe(['GET', 'GET']);
});

it('proves the tax from the rate lookup and lets a line whose tax is exactly what the rate gives through', function () {
    // 33.33 exclusive at 15 percent is 4.9995, which Xero and we both round to
    // 5.00: stored as 5.00 the line is untouched and the recode goes out.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['SubTotal' => 33.33, 'TotalTax' => 5.00, 'Total' => 38.33], [['UnitAmount' => 33.33, 'LineAmount' => 33.33, 'TaxAmount' => 5.00]]));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, transactionResponse('spend-uuid-2', ['SubTotal' => 33.33, 'TotalTax' => 5.00, 'Total' => 38.33], [['UnitAmount' => 33.33, 'LineAmount' => 33.33, 'TaxAmount' => 5.00, 'AccountCode' => '400']]));

    $result = xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400'));

    expect($result->after->accountCodes())->toBe(['400'])
        ->and(methodsOf($fake))->toBe(['GET', 'GET', 'POST']);
});

it('refuses a line whose stored tax is a cent off what the rate gives, before any write (invariant 15)', function () {
    // Stored 4.99 where 15 percent of 33.33 rounds to 5.00: a write would
    // "correct" the tax and move the total by that cent. A cent is not slack.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['SubTotal' => 33.33, 'TotalTax' => 4.99, 'Total' => 38.32], [['UnitAmount' => 33.33, 'LineAmount' => 33.33, 'TaxAmount' => 4.99]]));
    $fake->queue(200, providerResponse('xero/tax-rates'));

    try {
        xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400'));
        $this->fail('a cent of tax difference must be refused');
    } catch (ValidationException $e) {
        expect($e->reason)->toBe(ValidationException::REASON_TAX_OVERRIDE_WOULD_BE_LOST)
            ->and($e->getMessage())->toContain('4.99')
            ->and($e->getMessage())->toContain('5.00');
    }

    expect(methodsOf($fake))->toBe(['GET', 'GET']);
});

it('proves a line taxed with an archived rate from the archived lookup, made only when needed', function () {
    // A catch-up transaction is routinely coded with a rate the customer has
    // since archived. Refusing exactly those lines would defeat the point, so the
    // archived rates are fetched, and cached, the first time an active one is missing.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['TotalTax' => 8.0, 'Total' => 88.0], [['TaxType' => 'OLDGST', 'TaxAmount' => 8.0]]));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, ['TaxRates' => [['TaxType' => 'OLDGST', 'Name' => 'GST 10% (archived)', 'Status' => 'ARCHIVED', 'EffectiveRate' => 10.0]]]);
    $fake->queue(200, transactionResponse('spend-uuid-2', ['TotalTax' => 8.0, 'Total' => 88.0], [['TaxType' => 'OLDGST', 'TaxAmount' => 8.0, 'AccountCode' => '400']]));

    $result = xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400'));

    expect($result->after->accountCodes())->toBe(['400'])
        ->and(methodsOf($fake))->toBe(['GET', 'GET', 'GET', 'POST'])
        ->and(queryOf($fake, 1)['where'])->toBe('Status=="ACTIVE"')
        ->and(queryOf($fake, 2)['where'])->toBe('Status=="ARCHIVED"');
});

it('refuses a taxed line whose rate neither the active nor the archived lookup knows, and lets an untaxed one through', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', [], [['TaxType' => 'CUSTOM9']]));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, ['TaxRates' => []]);

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400')))
        ->toThrow(function (ValidationException $e): void {
            expect($e->reason)->toBe(ValidationException::REASON_TAX_RATE_UNKNOWN);
        });

    expect(methodsOf($fake))->toBe(['GET', 'GET', 'GET']);

    // The same unknown rate carrying no tax has nothing Xero could recompute.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['TotalTax' => 0.0, 'Total' => 80.0], [['TaxType' => 'CUSTOM9', 'TaxAmount' => 0.0]]));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, ['TaxRates' => []]);
    $fake->queue(200, transactionResponse('spend-uuid-2', ['TotalTax' => 0.0, 'Total' => 80.0], [['TaxType' => 'CUSTOM9', 'TaxAmount' => 0.0, 'AccountCode' => '400']]));

    xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('400'));

    expect(methodsOf($fake))->toBe(['GET', 'GET', 'GET', 'POST']);
});

it('deletes a spend money by status and answers with the deleted row', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1', ['Status' => 'DELETED']));

    $after = xeroReader($fake)->deleteBankTransaction(connection(), 'spend-uuid-1', 'delete-op-7');

    $body = $fake->requestBody(0);

    expect($fake->requests[0]->getMethod())->toBe('POST')
        ->and($fake->requests[0]->getUri()->getPath())->toBe('/api.xro/2.0/BankTransactions/spend-uuid-1')
        ->and($body)->toBe(['BankTransactions' => [['BankTransactionID' => 'spend-uuid-1', 'Status' => 'DELETED']]])
        ->and($fake->requests[0]->getHeaderLine('Idempotency-Key'))->toBe('delete-op-7')
        ->and($after->id)->toBe('spend-uuid-1')
        ->and($after->status)->toBe('DELETED')
        ->and($after->total->amount)->toBe(4250);
});

it('treats a transaction that is already gone as deleted rather than as an error', function () {
    $fake = fakeHttp();
    $fake->queue(404, ['Message' => 'not found']);

    $after = xeroReader($fake)->deleteBankTransaction(connection(), 'gone-uuid');

    expect($after->id)->toBe('gone-uuid')
        ->and($after->status)->toBe('DELETED')
        ->and($after->type)->toBeNull()
        ->and($after->total->amount)->toBe(0);
});

it('re-reads when the delete answers without a row, and maps a 404 there to deleted too', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['BankTransactions' => []]);
    $fake->queue(404, ['Message' => 'not found']);

    $after = xeroReader($fake)->deleteBankTransaction(connection(), 'spend-uuid-1');

    expect(methodsOf($fake))->toBe(['POST', 'GET'])
        ->and($after->status)->toBe('DELETED');
});

it('raises when Xero accepts the delete and still reports the transaction authorised', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1'));

    expect(fn () => xeroReader($fake)->deleteBankTransaction(connection(), 'spend-uuid-1'))
        ->toThrow(ValidationException::class, 'still reports it as AUTHORISED');
});

it('surfaces a refusal to delete, and refuses an empty id before any call', function () {
    $fake = fakeHttp();
    $fake->queue(400, providerResponse('xero/validation-error'));

    expect(fn () => xeroReader($fake)->deleteBankTransaction(connection(), 'spend-uuid-2'))
        ->toThrow(ValidationException::class);

    $fake = fakeHttp();

    expect(fn () => xeroReader($fake)->deleteBankTransaction(connection(), '  '))
        ->toThrow(InvalidPayloadException::class);

    expect($fake->requests)->toBeEmpty();
});

it('sends a four-place unit price back as it came, not rounded to cents', function () {
    // Quantity 3 at 1.3333 is a 4.00 line. Rounded to 1.33 and sent back, Xero
    // recomputes it as 3.99 and the total moves by a cent.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1', ['SubTotal' => 4.0, 'Total' => 4.0], [['Quantity' => 3.0, 'UnitAmount' => 1.3333, 'LineAmount' => 4.0]]));
    $fake->queue(200, transactionResponse('spend-uuid-1', ['SubTotal' => 4.0, 'Total' => 4.0], [['Quantity' => 3.0, 'UnitAmount' => 1.3333, 'LineAmount' => 4.0, 'AccountCode' => '400']]));

    $result = xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', BankTransactionChange::allLines('400'));

    $line = $fake->requestBody(1)['BankTransactions'][0]['LineItems'][0];

    expect(queryOf($fake, 0)['unitdp'])->toBe('4')
        // The write asks for four places too: Xero rounds a UnitAmount it is
        // handed to two places unless told otherwise, and 1.33 x 3 is 3.99.
        ->and($fake->requests[1]->getMethod())->toBe('POST')
        ->and(queryOf($fake, 1)['unitdp'])->toBe('4')
        ->and($line['UnitAmount'])->toEqual(1.3333)
        ->and($line['Quantity'])->toEqual(3.0)
        ->and($line['LineAmount'])->toEqual(4.0)
        ->and($result->before->lines[0]->unitAmountExact)->toBe('1.3333')
        // The cents figure is still there for display and matching.
        ->and($result->before->lines[0]->unitAmount?->amount)->toBe(133);
});

it('carries the currency rate and the item code round the trip', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1', ['CurrencyCode' => 'EUR', 'CurrencyRate' => 0.5842], [['ItemCode' => 'WIDGET']]));
    $fake->queue(200, transactionResponse('spend-uuid-1', ['CurrencyCode' => 'EUR', 'CurrencyRate' => 0.5842], [['ItemCode' => 'WIDGET', 'AccountCode' => '400']]));

    $result = xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', BankTransactionChange::allLines('400'));

    $body = $fake->requestBody(1)['BankTransactions'][0];

    expect($body['CurrencyRate'])->toEqual(0.5842)
        ->and($body['CurrencyCode'])->toBe('EUR')
        ->and($body['LineItems'][0]['ItemCode'])->toBe('WIDGET')
        ->and($result->before->currencyRate)->toEqual(0.5842)
        ->and($result->before->lines[0]->itemCode)->toBe('WIDGET');
});

it('raises when the provider accepted the recode and moved the money anyway', function () {
    // The one failure a recode cannot undo. Both states come back so a person can
    // see what happened and fix it in Xero.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1'));
    $fake->queue(200, transactionResponse('spend-uuid-1', ['SubTotal' => 36.96, 'TotalTax' => 5.54, 'Total' => 42.5], [['AccountCode' => '400']]));

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', BankTransactionChange::allLines('400')))
        ->toThrow(function (RecodeMovedMoneyException $e): void {
            expect($e->before->subTotal?->amount)->toBe(4250)
                ->and($e->after->subTotal?->amount)->toBe(3696)
                ->and($e->differences)->toContain('SubTotal 42.50 became 36.96')
                ->and($e->differences)->toContain('TotalTax 0.00 became 5.54');
        });
});

it('raises when the provider moved tax between lines or re-rounded a unit price with the headers unchanged', function () {
    // The reviewer's case: line taxes 4.99 and 5.01 come back 5.00 and 5.00. Every
    // header figure is where it was and yet each line's tax moved; likewise a
    // 1.3333 unit price re-rounded to 1.33 on a line whose amount still reads 4.00.
    $two = static fn (array $taxes, string $unitB): array => ['BankTransactions' => [[
        'BankTransactionID' => 'spend-uuid-9',
        'Type' => 'SPEND',
        'Status' => 'AUTHORISED',
        'LineAmountTypes' => 'Exclusive',
        'SubTotal' => 66.66,
        'TotalTax' => 10.0,
        'Total' => 76.66,
        'CurrencyCode' => 'USD',
        'UpdatedDateUTC' => '/Date(1787702400000+0000)/',
        'BankAccount' => ['AccountID' => 'bank-uuid'],
        'LineItems' => [
            ['LineItemID' => 'line-a', 'Quantity' => 1.0, 'UnitAmount' => 33.33, 'LineAmount' => 33.33, 'AccountCode' => '400', 'TaxType' => 'INPUT2', 'TaxAmount' => $taxes[0]],
            ['LineItemID' => 'line-b', 'Quantity' => 1.0, 'UnitAmount' => $unitB, 'LineAmount' => 33.33, 'AccountCode' => '400', 'TaxType' => 'INPUT2', 'TaxAmount' => $taxes[1]],
        ],
    ]]];

    $fake = fakeHttp();
    $fake->queue(200, $two([5.0, 5.0], '33.3333'));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, $two([4.99, 5.01], '33.33'));

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-9', BankTransactionChange::allLines('429')))
        ->toThrow(function (RecodeMovedMoneyException $e): void {
            expect($e->differences)->toContain('line line-a TaxAmount 5.00 became 4.99')
                ->and($e->differences)->toContain('line line-b TaxAmount 5.00 became 5.01')
                ->and($e->differences)->toContain('line line-b UnitAmount 33.3333 became 33.33')
                ->and(implode(' ', $e->differences))->not->toContain('Total ');
        });
});

it('refuses a stale expectation on its own read, with no write', function () {
    // The host decided against a line coded 429 and modified at one instant; the
    // connector's read finds it coded 400. Somebody was there in between.
    $decidedAgainst = BankTransactionData::fromArray(
        xeroReaderRead(transactionResponse('spend-uuid-2'))->toArray(),
    );

    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['UpdatedDateUTC' => '/Date(1787832000000+0000)/'], [['AccountCode' => '400']]));

    expect(fn () => xeroReader($fake)->recodeBankTransaction(
        connection(),
        'spend-uuid-2',
        BankTransactionChange::allLines('450'),
        RecodeExpectation::from($decidedAgainst),
    ))->toThrow(function (PreconditionFailedException $e): void {
        expect($e->fresh->accountCodes())->toBe(['400'])
            ->and($e->differences)->toContain('line line-uuid-2 is coded to 400, not 429')
            ->and($e->differences[1])->toStartWith('modified at');
    });

    expect(methodsOf($fake))->toBe(['GET']);
});

it('treats a retry whose read already shows the change applied as landed, without a second write', function () {
    // The write landed and its response was lost. The retry carries the same
    // expectation and key; its own read shows the intended coding and a later
    // stamp. That is not a stale decision: nothing is sent, the read is the
    // result, and the before-state is the expectation laid over the read.
    $decidedAgainst = xeroReaderRead(transactionResponse('spend-uuid-2'));

    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['UpdatedDateUTC' => '/Date(1787745700000+0000)/'], [['AccountCode' => '450']]));

    $result = xeroReader($fake)->recodeBankTransaction(
        connection(),
        'spend-uuid-2',
        BankTransactionChange::allLines('450'),
        RecodeExpectation::from($decidedAgainst),
        'recode-op-123',
    );

    expect(methodsOf($fake))->toBe(['GET'])
        ->and($result->recovered)->toBeTrue()
        ->and($result->after->accountCodes())->toBe(['450'])
        ->and($result->before->accountCodesByLine())->toBe($decidedAgainst->accountCodesByLine())
        ->and($result->before->updatedDateUtc?->format('U'))->toBe($decidedAgainst->updatedDateUtc?->format('U'))
        ->and($result->before->total->amount)->toBe($result->after->total->amount)
        ->and($result->changedCoding())->toBeTrue();
});

it('still refuses a read that differs in any way other than the change itself', function () {
    // Coded to the intended account AND to something else on another line, or to
    // a different account altogether: a person was here, and the decision is stale.
    $decidedAgainst = xeroReaderRead(transactionResponse('spend-uuid-2'));

    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['UpdatedDateUTC' => '/Date(1787745700000+0000)/'], [['AccountCode' => '470']]));

    expect(fn () => xeroReader($fake)->recodeBankTransaction(
        connection(),
        'spend-uuid-2',
        BankTransactionChange::allLines('450'),
        RecodeExpectation::from($decidedAgainst),
        'recode-op-123',
    ))->toThrow(PreconditionFailedException::class);

    expect(methodsOf($fake))->toBe(['GET']);
});

it('never reads a change that matches no line as already landed', function () {
    // The transaction was touched (its stamp moved) but its codes are as expected,
    // and the change names a line that is not on it. Nothing could have landed;
    // that is a stale decision, not a lost response.
    $decidedAgainst = xeroReaderRead(transactionResponse('spend-uuid-2'));

    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['UpdatedDateUTC' => '/Date(1787745700000+0000)/']));

    expect(fn () => xeroReader($fake)->recodeBankTransaction(
        connection(),
        'spend-uuid-2',
        new BankTransactionChange([LineCoding::forLine('line-that-is-not-there', '450')]),
        RecodeExpectation::from($decidedAgainst),
        'recode-op-123',
    ))->toThrow(PreconditionFailedException::class);

    expect(methodsOf($fake))->toBe(['GET']);
});

it('refuses to recode a transaction whose type it does not know, rather than sending it back as a SPEND', function () {
    // A type this build has no case for reads back as null. Defaulting the write
    // to SPEND would turn a money-in line into money out.
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['Type' => 'RECEIVE-SOMETHING-NEW']));
    $fake->queue(200, providerResponse('xero/tax-rates'));

    try {
        xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('450'));
        $this->fail('an unknown type must be refused before any write');
    } catch (ValidationException $e) {
        expect($e->reason)->toBe(ValidationException::REASON_TYPE_UNKNOWN)
            ->and($e->getMessage())->toContain('spend-uuid-2');
    }

    expect(methodsOf($fake))->not->toContain('POST');
});

it('refuses to recode a transfer, overpayment or prepayment leg before any write (invariant 12)', function () {
    foreach (['SPEND-TRANSFER', 'RECEIVE-TRANSFER', 'SPEND-OVERPAYMENT', 'RECEIVE-PREPAYMENT'] as $type) {
        $fake = fakeHttp();
        $fake->queue(200, transactionResponse('spend-uuid-2', ['Type' => $type]));
        $fake->queue(200, providerResponse('xero/tax-rates'));

        try {
            xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('450'));
            $this->fail("a {$type} must be refused before any write");
        } catch (ValidationException $e) {
            expect($e->reason)->toBe(ValidationException::REASON_TYPE_NOT_RECODABLE)
                ->and($e->getMessage())->toContain($type);
        }

        expect(methodsOf($fake))->not->toContain('POST');
    }
});

it('sends a RECEIVE back as a RECEIVE', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2', ['Type' => 'RECEIVE']));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, transactionResponse('spend-uuid-2', ['Type' => 'RECEIVE'], [['AccountCode' => '450']]));

    xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('450'));

    expect($fake->requestBody(2)['BankTransactions'][0]['Type'])->toBe('RECEIVE');
});

it('reports a line tax that appeared or vanished as moved money', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2'));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $row = transactionResponse('spend-uuid-2', lineOverrides: [['AccountCode' => '450']]);
    unset($row['BankTransactions'][0]['LineItems'][0]['TaxAmount']);
    $fake->queue(200, $row);

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-2', BankTransactionChange::allLines('450')))
        ->toThrow(function (RecodeMovedMoneyException $e): void {
            expect(implode(' ', $e->differences))->toContain('line line-uuid-2 TaxAmount 12.00 became none');
        });
});

it('writes once when the expectation still holds, with the idempotency key on the wire', function () {
    $decidedAgainst = xeroReaderRead(transactionResponse('spend-uuid-2'));

    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-2'));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, transactionResponse('spend-uuid-2', lineOverrides: [['AccountCode' => '450']]));

    xeroReader($fake)->recodeBankTransaction(
        connection(),
        'spend-uuid-2',
        BankTransactionChange::allLines('450'),
        RecodeExpectation::from($decidedAgainst),
        'recode-op-123',
    );

    expect(methodsOf($fake))->toBe(['GET', 'GET', 'POST'])
        ->and($fake->requests[2]->getHeaderLine('Idempotency-Key'))->toBe('recode-op-123');
});

it('sets a contact from the change and leaves everything else as it came', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1', ['Contact' => null]));
    $fake->queue(200, transactionResponse('spend-uuid-1', ['Contact' => ['ContactID' => 'contact-google', 'Name' => 'Google Workspace']], [['AccountCode' => '400']]));

    $change = BankTransactionChange::allLines('400', [new TrackingRef('cat-uuid', 'opt-uuid')])->withContact('contact-google');

    $result = xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', $change);

    $body = $fake->requestBody(1)['BankTransactions'][0];

    expect($body['Contact'])->toBe(['ContactID' => 'contact-google'])
        ->and($body['BankAccount'])->toBe(['AccountID' => 'bank-uuid'])
        ->and($body['Date'])->toBe('2026-08-28')
        ->and($body['Reference'])->toBe('VISA 4242')
        ->and($body['Status'])->toBe('AUTHORISED')
        ->and($body['CurrencyCode'])->toBe('USD')
        ->and($body['LineAmountTypes'])->toBe('Exclusive')
        ->and($body['LineItems'][0]['LineItemID'])->toBe('line-uuid-1')
        ->and($body['LineItems'][0]['UnitAmount'])->toEqual(42.5)
        ->and($body['LineItems'][0]['LineAmount'])->toEqual(42.5)
        ->and($body['LineItems'][0]['Tracking'])->toBe([['TrackingCategoryID' => 'cat-uuid', 'TrackingOptionID' => 'opt-uuid']])
        ->and($result->before->contactId)->toBeNull()
        ->and($result->after->contactId)->toBe('contact-google');
});

it('keeps the current contact when the change names none', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1'));
    $fake->queue(200, transactionResponse('spend-uuid-1', lineOverrides: [['AccountCode' => '400']]));

    xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', BankTransactionChange::allLines('400'));

    expect($fake->requestBody(1)['BankTransactions'][0]['Contact'])->toBe(['ContactID' => 'contact-uuid-1']);
});

it('refuses a change that changes nothing before making any request', function () {
    $fake = fakeHttp();

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', new BankTransactionChange))
        ->toThrow(InvalidPayloadException::class);

    expect(fn () => xeroReader($fake)->recodeBankTransaction(connection(), 'spend-uuid-1', new BankTransactionChange([LineCoding::forAllLines(null)])))
        ->toThrow(InvalidPayloadException::class);

    expect($fake->requests)->toBeEmpty();
});

it('keeps the coding-only wrapper working, with the same guards', function () {
    $fake = fakeHttp();
    $fake->queue(200, transactionResponse('spend-uuid-1'));
    $fake->queue(200, transactionResponse('spend-uuid-1', lineOverrides: [['AccountCode' => '400']]));

    $after = xeroReader($fake)->updateBankTransactionCoding(connection(), 'spend-uuid-1', [LineCoding::forAllLines('400')]);

    expect($after->accountCodes())->toBe(['400'])
        ->and($fake->requestBody(1)['BankTransactions'][0]['LineAmountTypes'])->toBe('Exclusive');
});

it('reads a system account marker off the chart, so a holding set can leave those out', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Accounts' => [
        ['AccountID' => 'a-1', 'Name' => 'Suspense', 'Code' => '250', 'Type' => 'CURRLIAB', 'SystemAccount' => ''],
        ['AccountID' => 'a-2', 'Name' => 'GST', 'Code' => '820', 'Type' => 'CURRLIAB', 'SystemAccount' => 'GST'],
        ['AccountID' => 'a-3', 'Name' => 'Office', 'Code' => '429', 'Type' => 'EXPENSE'],
    ]]);

    $accounts = xeroReader($fake)->chartOfAccounts(connection());
    $byCode = [];

    foreach ($accounts as $account) {
        $byCode[$account->code] = $account;
    }

    expect($byCode['250']->isSystem())->toBeFalse()
        ->and($byCode['820']->isSystem())->toBeTrue()
        ->and($byCode['820']->systemAccount)->toBe('GST')
        ->and($byCode['429']->isSystem())->toBeFalse()
        ->and($byCode['820']->toArray()['system_account'])->toBe('GST');
});

it('finds a contact by exact name without creating one', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-google', 'Name' => 'Google Workspace', 'ContactStatus' => 'ACTIVE', 'IsSupplier' => true]]]);
    $fake->queue(200, ['Contacts' => []]);

    $reader = xeroReader($fake);

    $found = $reader->findContactByName(connection(), 'Google Workspace');
    $missing = $reader->findContactByName(connection(), 'Nobody Ltd');

    expect($found?->id)->toBe('contact-google')
        ->and($found?->name)->toBe('Google Workspace')
        ->and($found?->isActive())->toBeTrue()
        ->and($found?->isSupplier)->toBeTrue()
        ->and($missing)->toBeNull()
        ->and(methodsOf($fake))->toBe(['GET', 'GET'])
        ->and(queryOf($fake, 0)['where'])->toBe('Name=="Google Workspace"');
});

it('answers null rather than guessing for a name the where clause cannot carry', function () {
    $fake = fakeHttp();

    expect(xeroReader($fake)->findContactByName(connection(), 'Bob "The Builder" Ltd'))->toBeNull()
        ->and(xeroReader($fake)->findContactByName(connection(), '   '))->toBeNull()
        ->and($fake->requests)->toBeEmpty();
});

/**
 * Read one transaction through the connector, off a canned body, for an expectation.
 */
function xeroReaderRead(array $body): BankTransactionData
{
    $fake = fakeHttp();
    $fake->queue(200, $body);

    $read = xeroReader($fake)->findBankTransaction(connection(), (string) $body['BankTransactions'][0]['BankTransactionID']);

    assert($read !== null);

    return $read;
}

it('round-trips every amount of a two-line exclusive transaction through a recode and its revert (invariant 15)', function () {
    // Two lines, different quantities, a four-place unit price, one taxed line,
    // exclusive tax mode. A rule recodes line-b; a revert puts it back. Neither
    // POST may carry anything but the account code that changed.
    $two = static fn (string $codeB): array => ['BankTransactions' => [[
        'BankTransactionID' => 'spend-uuid-9',
        'Type' => 'SPEND',
        'Status' => 'AUTHORISED',
        'LineAmountTypes' => 'Exclusive',
        'SubTotal' => 130.0,
        'TotalTax' => 12.0,
        'Total' => 142.0,
        'CurrencyCode' => 'USD',
        'CurrencyRate' => 1.0,
        'UpdatedDateUTC' => '/Date(1787702400000+0000)/',
        'Contact' => ['ContactID' => 'contact-uuid-9', 'Name' => 'Two Line Co'],
        'BankAccount' => ['AccountID' => 'bank-uuid', 'Code' => '090', 'Name' => 'Business Checking'],
        'LineItems' => [
            ['LineItemID' => 'line-a', 'Description' => 'Taxed', 'Quantity' => 1.0, 'UnitAmount' => 80.0, 'LineAmount' => 80.0, 'AccountCode' => '400', 'TaxType' => 'INPUT2', 'TaxAmount' => 12.0],
            ['LineItemID' => 'line-b', 'Description' => 'Untaxed, priced', 'Quantity' => 3.0, 'UnitAmount' => 16.6667, 'LineAmount' => 50.0, 'AccountCode' => $codeB, 'TaxAmount' => 0.0],
        ],
    ]]];

    $fake = fakeHttp();
    // The recode: read, tax rates, POST answered with the new code on line-b.
    $fake->queue(200, $two('850'));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, $two('429'));
    // The revert: read, tax rates again (the reader here caches nothing), POST
    // answered with the old code back.
    $fake->queue(200, $two('429'));
    $fake->queue(200, providerResponse('xero/tax-rates'));
    $fake->queue(200, $two('850'));

    $connector = xeroReader($fake);

    $recoded = $connector->recodeBankTransaction(connection(), 'spend-uuid-9', new BankTransactionChange([LineCoding::forLine('line-b', '429')]));
    $reverted = $connector->recodeBankTransaction(connection(), 'spend-uuid-9', new BankTransactionChange([LineCoding::forLine('line-b', '850')]));

    expect(methodsOf($fake))->toBe(['GET', 'GET', 'POST', 'GET', 'GET', 'POST'])
        ->and($recoded->after->accountCodesByLine())->toBe(['line-a' => '400', 'line-b' => '429'])
        ->and($reverted->after->accountCodesByLine())->toBe(['line-a' => '400', 'line-b' => '850']);

    foreach ([2 => '429', 5 => '850'] as $request => $codeB) {
        $body = $fake->requestBody($request)['BankTransactions'][0];
        $lines = $body['LineItems'];

        expect($body['LineAmountTypes'])->toBe('Exclusive')
            ->and($body)->not->toHaveKeys(['Total', 'SubTotal', 'TotalTax'])
            ->and(count($lines))->toBe(2)
            ->and($lines[0]['LineItemID'])->toBe('line-a')
            ->and($lines[0]['Quantity'])->toEqual(1.0)
            ->and($lines[0]['UnitAmount'])->toEqual(80.0)
            ->and($lines[0]['LineAmount'])->toEqual(80.0)
            ->and($lines[0]['TaxType'])->toBe('INPUT2')
            ->and($lines[0]['AccountCode'])->toBe('400')
            ->and($lines[0])->not->toHaveKey('TaxAmount')
            ->and($lines[1]['LineItemID'])->toBe('line-b')
            ->and($lines[1]['Quantity'])->toEqual(3.0)
            ->and($lines[1]['UnitAmount'])->toEqual(16.6667)
            ->and($lines[1]['LineAmount'])->toEqual(50.0)
            ->and($lines[1])->not->toHaveKey('TaxType')
            ->and($lines[1]['AccountCode'])->toBe($codeB);
    }
});
