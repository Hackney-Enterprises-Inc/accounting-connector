<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\JournalLine;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Data\PaymentData;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

it('refuses a line that is neither priced nor totalled', function () {
    new LineItem('Widgets');
})->throws(InvalidPayloadException::class);

it('totals a priced line by quantity', function () {
    expect((new LineItem('Widgets', unitAmount: Money::cents(2500), quantity: 3))->total()->amount)
        ->toBe(7500);
});

it('takes a flat line amount as given, ignoring quantity', function () {
    $line = new LineItem('Split', lineAmount: Money::cents(3333), quantity: 7);

    expect($line->total()->amount)->toBe(3333)
        ->and($line->isFlatAmount())->toBeTrue();
});

it('refuses a transaction with no lines', function () {
    new BillData(vendor: 'Acme', date: new DateTimeImmutable, lines: []);
})->throws(InvalidPayloadException::class, 'at least one line item');

it('refuses a currency that is not a three-letter code', function () {
    new BillData(
        vendor: 'Acme',
        date: new DateTimeImmutable,
        lines: [new LineItem('x', unitAmount: Money::cents(1))],
        currency: 'DOLLARS',
    );
})->throws(InvalidPayloadException::class, '3-letter ISO code');

it('sums a transaction total across its lines', function () {
    $bill = new BillData(
        vendor: 'Acme',
        date: new DateTimeImmutable,
        lines: [
            new LineItem('a', unitAmount: Money::cents(2500), quantity: 3),
            new LineItem('b', lineAmount: Money::cents(1099)),
        ],
    );

    expect($bill->total()->amount)->toBe(8599);
});

it('accepts a vendor as either a name or a full contact', function () {
    $byName = new BillData('Acme', new DateTimeImmutable, [new LineItem('x', unitAmount: Money::cents(1))]);
    $byRecord = new BillData(
        ContactData::vendor('Acme', 'vendor-7'),
        new DateTimeImmutable,
        [new LineItem('x', unitAmount: Money::cents(1))],
    );

    expect($byName->vendorContact()->name)->toBe('Acme')
        ->and($byName->vendorContact()->role)->toBe(EntityType::Vendor)
        ->and($byRecord->vendorContact()->localId())->toBe('vendor-7');
});

it('keys a nameless-id contact on its normalised name', function () {
    // A vendor that only ever existed as text on a receipt still has to be found
    // again on the next sync.
    expect(ContactData::vendor('Acme Supply')->mapKey())->toBe('name:acme supply')
        ->and(ContactData::vendor('  ACME Supply  ')->mapKey())->toBe('name:acme supply');
});

it('refuses a contact with no name', function () {
    ContactData::vendor('   ');
})->throws(InvalidPayloadException::class, 'needs a name');

it('refuses a contact role that is not a contact', function () {
    new ContactData(name: 'Acme', role: EntityType::Bill);
})->throws(InvalidPayloadException::class, 'must be customer or vendor');

it('refuses a journal that does not balance, and says by how much', function () {
    // Both providers reject an unbalanced journal after the round trip, with a
    // message that does not name the amount it is out by.
    new JournalData(
        narration: 'Payout',
        date: new DateTimeImmutable,
        lines: [
            new JournalLine('400', Money::cents(10000)),
            new JournalLine('090', Money::cents(-9950)),
        ],
    );
})->throws(InvalidPayloadException::class, 'out by 50 minor units');

it('accepts a journal that balances', function () {
    $journal = new JournalData(
        narration: 'Payout',
        date: new DateTimeImmutable,
        lines: [
            new JournalLine('400', Money::cents(10000)),
            new JournalLine('090', Money::cents(-10000)),
        ],
    );

    expect($journal->imbalance())->toBe(0)
        ->and($journal->lines[0]->isDebit())->toBeTrue()
        ->and($journal->lines[1]->isDebit())->toBeFalse();
});

it('refuses a one-sided journal', function () {
    new JournalData('x', new DateTimeImmutable, [new JournalLine('400', Money::zero())]);
})->throws(InvalidPayloadException::class, 'at least two lines');

it('refuses a payment with no invoice or a zero amount', function () {
    expect(fn () => new PaymentData('', Money::cents(100), new DateTimeImmutable))
        ->toThrow(InvalidPayloadException::class, 'external id of the invoice')
        ->and(fn () => new PaymentData('inv-1', Money::zero(), new DateTimeImmutable))
        ->toThrow(InvalidPayloadException::class, 'zero cannot be posted');
});

it('knows which half of the ledger an entity type belongs to', function () {
    expect(EntityType::Invoice->isReceivable())->toBeTrue()
        ->and(EntityType::Invoice->isPayable())->toBeFalse()
        ->and(EntityType::Bill->isPayable())->toBeTrue()
        ->and(EntityType::Vendor->isContact())->toBeTrue()
        ->and(EntityType::Journal->isReceivable())->toBeFalse()
        ->and(EntityType::Journal->isPayable())->toBeFalse();
});
