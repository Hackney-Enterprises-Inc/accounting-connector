<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionLine;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Data\Contact;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Data\RecodeExpectation;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Enums\LineAmountType;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\PreconditionFailedException;
use Hei\AccountingConnector\Exceptions\RecodeMovedMoneyException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Testing\FakeConnector;

/**
 * A minimal transaction for the fake's books.
 */
function fakeTransaction(
    string $id,
    string $date,
    ?string $updated = null,
    BankTransactionType $type = BankTransactionType::Spend,
    int $cents = 1000,
): BankTransactionData {
    return new BankTransactionData(
        id: $id,
        type: $type,
        date: new DateTimeImmutable($date),
        total: Money::cents($cents),
        currency: 'USD',
        status: 'AUTHORISED',
        updatedDateUtc: $updated === null ? null : new DateTimeImmutable($updated),
    );
}

/**
 * @return array<int, string>
 */
function idsOf(FakeConnector $fake, BankTransactionQuery $query): array
{
    return array_map(
        fn (BankTransactionData $row): string => $row->id,
        $fake->listBankTransactions(connection(), $query)->transactions,
    );
}

it('orders by date in either direction with the id as the tiebreak, like Xero', function () {
    $fake = (new FakeConnector)->withBankTransactions(
        fakeTransaction('b', '2026-03-02'),
        fakeTransaction('a', '2026-03-02'),
        fakeTransaction('c', '2026-01-15'),
        fakeTransaction('d', '2026-06-30'),
    );

    expect(idsOf($fake, new BankTransactionQuery(order: 'Date ASC')))->toBe(['c', 'a', 'b', 'd'])
        ->and(idsOf($fake, new BankTransactionQuery(order: 'Date DESC')))->toBe(['d', 'b', 'a', 'c']);
});

it('defaults to the order Xero applies when nothing is asked for', function () {
    // UpdatedDateUTC ASC with the id as a secondary order. A host that mistakenly
    // relied on insertion order finds out here rather than against Xero.
    $fake = (new FakeConnector)->withBankTransactions(
        fakeTransaction('late', '2026-03-01', '2026-03-05 10:00:00'),
        fakeTransaction('early', '2026-03-01', '2026-03-01 10:00:00'),
        fakeTransaction('tie-b', '2026-03-01', '2026-03-03 10:00:00'),
        fakeTransaction('tie-a', '2026-03-01', '2026-03-03 10:00:00'),
    );

    expect(idsOf($fake, new BankTransactionQuery))->toBe(['early', 'tie-a', 'tie-b', 'late'])
        ->and(idsOf($fake, new BankTransactionQuery(order: 'UpdatedDateUTC DESC')))->toBe(['late', 'tie-b', 'tie-a', 'early']);
});

it('pages at the requested size and fills the pagination counts', function () {
    $rows = [];

    for ($i = 1; $i <= 7; $i++) {
        $rows[] = fakeTransaction(sprintf('t%02d', $i), '2026-03-'.sprintf('%02d', $i));
    }

    $fake = (new FakeConnector)->withBankTransactions(...$rows);

    $first = $fake->listBankTransactions(connection(), new BankTransactionQuery(order: 'Date ASC', pageSize: 3));
    $last = $fake->listBankTransactions(connection(), new BankTransactionQuery(order: 'Date ASC', pageSize: 3, page: 3));

    expect($first->count())->toBe(3)
        ->and($first->pageSize)->toBe(3)
        ->and($first->itemCount)->toBe(7)
        ->and($first->pageCount)->toBe(3)
        ->and($first->hasMore())->toBeTrue()
        ->and($last->count())->toBe(1)
        ->and($last->hasMore())->toBeFalse()
        ->and($last->transactions[0]->id)->toBe('t07');
});

it('uses the package default page size when the query leaves it unset', function () {
    $fake = (new FakeConnector)->withBankTransactions(fakeTransaction('x', '2026-01-01'));

    $page = $fake->listBankTransactions(connection(), new BankTransactionQuery);

    expect($page->pageSize)->toBe(BankTransactionQuery::DEFAULT_PAGE_SIZE)
        ->and($page->itemCount)->toBe(1)
        ->and($page->pageCount)->toBe(1);
});

it('serves every type when the query does not narrow by one', function () {
    $rows = [];

    foreach (BankTransactionType::cases() as $type) {
        $rows[] = fakeTransaction(strtolower($type->value), '2026-03-01', type: $type);
    }

    $fake = (new FakeConnector)->withBankTransactions(...$rows);

    expect($fake->listBankTransactions(connection(), new BankTransactionQuery(type: null))->count())->toBe(8)
        ->and(idsOf($fake, new BankTransactionQuery(type: BankTransactionType::Receive)))->toBe(['receive'])
        ->and(idsOf($fake, new BankTransactionQuery))->toBe(['spend']);
});

it('fails the next bank transaction call once, then answers again', function () {
    $fake = (new FakeConnector)
        ->withBankTransactions(fakeTransaction('x', '2026-01-01'))
        ->failNextBankTransactionCall(new ServerException('Xero is down'));

    expect(fn () => $fake->listBankTransactions(connection(), new BankTransactionQuery))
        ->toThrow(ServerException::class);

    expect($fake->listBankTransactions(connection(), new BankTransactionQuery)->count())->toBe(1);
});

/**
 * A coded, single-line transaction for recode tests.
 */
function codedTransaction(string $id = 'txn-1', string $code = '250', ?string $contactId = null): BankTransactionData
{
    return new BankTransactionData(
        id: $id,
        type: BankTransactionType::Spend,
        date: new DateTimeImmutable('2026-03-01'),
        total: Money::cents(4250),
        subTotal: Money::cents(4250),
        totalTax: Money::zero(),
        currency: 'USD',
        status: 'AUTHORISED',
        contactId: $contactId,
        lines: [new BankTransactionLine(
            lineItemId: 'line-1',
            description: 'Coffee',
            quantity: 1.0,
            unitAmount: Money::cents(4250),
            lineAmount: Money::cents(4250),
            accountCode: $code,
            taxType: 'NONE',
        )],
        updatedDateUtc: new DateTimeImmutable('2026-03-01 10:00:00'),
        lineAmountType: LineAmountType::NoTax,
    );
}

it('applies a change, keeps it for the next find, and records what it carried', function () {
    $fake = (new FakeConnector)
        ->withBankTransactions(codedTransaction())
        ->withContacts(new Contact('contact-google', 'Google Workspace'));

    $read = $fake->findBankTransaction(connection(), 'txn-1');
    assert($read !== null);

    $result = $fake->recodeBankTransaction(
        connection(),
        'txn-1',
        BankTransactionChange::allLines('429')->withContact('contact-google'),
        RecodeExpectation::from($read),
        'recode-op-1',
    );

    expect($result->before->accountCodes())->toBe(['250'])
        ->and($result->after->accountCodes())->toBe(['429'])
        ->and($result->after->contactId)->toBe('contact-google')
        ->and($result->after->contactName)->toBe('Google Workspace')
        ->and($result->after->total->amount)->toBe(4250)
        ->and($fake->findBankTransaction(connection(), 'txn-1')?->accountCodes())->toBe(['429'])
        ->and($fake->changes)->toHaveCount(1)
        ->and($fake->changes[0]['idempotency_key'])->toBe('recode-op-1')
        ->and($fake->changes[0]['expectation'])->toBeInstanceOf(RecodeExpectation::class)
        // The old shape still fills for hosts asserting on it.
        ->and($fake->recodings[0]['external_id'])->toBe('txn-1');
});

it('refuses a stale expectation when the books changed between the two reads', function () {
    $fake = (new FakeConnector)->withBankTransactions(codedTransaction());

    $read = $fake->findBankTransaction(connection(), 'txn-1');
    assert($read !== null);

    // A bookkeeper codes the line to 400 between the host's read and the write.
    $fake->mutateBeforeNextRecode(fn (?BankTransactionData $stored): ?BankTransactionData => codedTransaction(code: '400'));

    expect(fn () => $fake->recodeBankTransaction(connection(), 'txn-1', BankTransactionChange::allLines('429'), RecodeExpectation::from($read)))
        ->toThrow(function (PreconditionFailedException $e): void {
            expect($e->fresh->accountCodes())->toBe(['400'])
                ->and($e->differences)->toContain('line line-1 is coded to 400, not 250');
        });

    // Nothing was written and nothing was recorded as a change.
    expect($fake->findBankTransaction(connection(), 'txn-1')?->accountCodes())->toBe(['400'])
        ->and($fake->changes)->toBeEmpty();
});

it('raises moved money when told to answer with a different total', function () {
    $fake = (new FakeConnector)->withBankTransactions(codedTransaction());

    $moved = new BankTransactionData(
        id: 'txn-1',
        type: BankTransactionType::Spend,
        date: new DateTimeImmutable('2026-03-01'),
        total: Money::cents(4000),
        currency: 'USD',
        status: 'AUTHORISED',
        lines: [new BankTransactionLine(lineItemId: 'line-1', quantity: 1.0, unitAmount: Money::cents(4000), lineAmount: Money::cents(4000), accountCode: '429')],
        lineAmountType: LineAmountType::NoTax,
    );

    $fake->nextRecodeReturns($moved);

    expect(fn () => $fake->recodeBankTransaction(connection(), 'txn-1', BankTransactionChange::allLines('429')))
        ->toThrow(function (RecodeMovedMoneyException $e): void {
            expect($e->before->total->amount)->toBe(4250)
                ->and($e->after->total->amount)->toBe(4000)
                ->and($e->differences)->toContain('Total 42.50 became 40.00');
        });

    // The write landed, as it would have in Xero.
    expect($fake->findBankTransaction(connection(), 'txn-1')?->total->amount)->toBe(4000);
});

it('refuses an empty change and a refused recode carries its reason', function () {
    $fake = (new FakeConnector)->withBankTransactions(codedTransaction());

    expect(fn () => $fake->recodeBankTransaction(connection(), 'txn-1', new BankTransactionChange))
        ->toThrow(InvalidPayloadException::class);

    $fake->failNextRecoding(new ValidationException('tax override', reason: ValidationException::REASON_TAX_OVERRIDE_WOULD_BE_LOST));

    expect(fn () => $fake->recodeBankTransaction(connection(), 'txn-1', BankTransactionChange::allLines('429')))
        ->toThrow(function (ValidationException $e): void {
            expect($e->reason)->toBe(ValidationException::REASON_TAX_OVERRIDE_WOULD_BE_LOST);
        });

    expect($fake->changes)->toBeEmpty();
});

it('keeps the coding-only wrapper', function () {
    $fake = (new FakeConnector)->withBankTransactions(codedTransaction());

    $after = $fake->updateBankTransactionCoding(connection(), 'txn-1', [LineCoding::forAllLines('429')]);

    expect($after->accountCodes())->toBe(['429'])
        ->and($fake->recodings)->toHaveCount(1);
});

it('finds a stocked contact by name and records the lookups', function () {
    $fake = (new FakeConnector)->withContacts(new Contact('contact-google', 'Google Workspace'));

    expect($fake->findContactByName(connection(), 'google workspace')?->id)->toBe('contact-google')
        ->and($fake->findContactByName(connection(), 'Nobody'))->toBeNull()
        ->and($fake->contactLookups)->toBe(['google workspace', 'Nobody']);

    $fake->flush();

    expect($fake->contactLookups)->toBeEmpty()
        ->and($fake->findContactByName(connection(), 'Google Workspace'))->toBeNull();
});

it('deletes by status, keeps the row for a later find, and records the key', function () {
    $fake = (new FakeConnector)->withBankTransactions(codedTransaction());

    $after = $fake->deleteBankTransaction(connection(), 'txn-1', 'delete-op-1');

    expect($after->status)->toBe('DELETED')
        ->and($after->total->amount)->toBe(4250)
        ->and($fake->findBankTransaction(connection(), 'txn-1')?->status)->toBe('DELETED')
        ->and($fake->deleted)->toBe([['external_id' => 'txn-1', 'idempotency_key' => 'delete-op-1']]);

    // An id the fake never held answers as a 404 does: gone, by id.
    $gone = $fake->deleteBankTransaction(connection(), 'never-there');

    expect($gone->status)->toBe('DELETED')
        ->and($gone->total->amount)->toBe(0)
        ->and($fake->deleted)->toHaveCount(2);

    $fake->flush();

    expect($fake->deleted)->toBeEmpty();
});

it('fails a delete on the queued bank transaction failure', function () {
    $fake = (new FakeConnector)
        ->withBankTransactions(codedTransaction())
        ->failNextBankTransactionCall(new ServerException('Xero is down'));

    expect(fn () => $fake->deleteBankTransaction(connection(), 'txn-1'))->toThrow(ServerException::class);

    expect($fake->deleted)->toBeEmpty()
        ->and($fake->findBankTransaction(connection(), 'txn-1')?->status)->toBe('AUTHORISED');
});

it('runs a callback once after the next list, so the books can change between two pages', function () {
    $fake = (new FakeConnector)->withBankTransactions(fakeTransaction('one', '2026-09-01'));

    $seen = 0;
    $fake->afterNextBankTransactionCall(function () use ($fake, &$seen): void {
        $seen++;
        $fake->withBankTransactions(fakeTransaction('two', '2026-09-02'));
    });

    expect(idsOf($fake, new BankTransactionQuery))->toBe(['one'])
        ->and($seen)->toBe(1)
        ->and(idsOf($fake, new BankTransactionQuery))->toBe(['one', 'two'])
        ->and($seen)->toBe(1);
});

it('forgets a queued after-list callback on flush like every other one-shot hook', function () {
    $fake = (new FakeConnector)->withBankTransactions(fakeTransaction('one', '2026-09-01'));
    $ran = false;
    $fake->afterNextBankTransactionCall(function () use (&$ran): void {
        $ran = true;
    });

    $fake->flush();
    $fake->withBankTransactions(fakeTransaction('two', '2026-09-02'));
    idsOf($fake, new BankTransactionQuery);

    expect($ran)->toBeFalse();
});

it('treats a retry whose read already carries the change as landed, like the real connector', function () {
    $fake = (new FakeConnector)->withBankTransactions(codedTransaction('one', '250'));
    $before = $fake->listBankTransactions(connection(), new BankTransactionQuery)->transactions[0];

    // The first write lands; imagine its response was lost.
    $fake->recodeBankTransaction(connection(), 'one', BankTransactionChange::allLines('429'), RecodeExpectation::from($before), 'op-1');
    $writes = count($fake->changes);

    // The retry: same expectation, same key. No second write, the read is the result.
    $result = $fake->recodeBankTransaction(connection(), 'one', BankTransactionChange::allLines('429'), RecodeExpectation::from($before), 'op-1');

    expect($result->recovered)->toBeTrue()
        ->and(count($fake->changes))->toBe($writes)
        ->and($result->after->accountCodes())->toBe(['429'])
        ->and($result->before->accountCodesByLine())->toBe($before->accountCodesByLine());
});
