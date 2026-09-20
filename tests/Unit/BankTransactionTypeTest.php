<?php

declare(strict_types=1);

use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Enums\MoneyDirection;

it('treats only plain spend and receive as matchable', function () {
    // A transfer leg is half of a pair between the customer's own accounts and a
    // prepayment or overpayment line is a control account waiting to be allocated.
    // None of them is ever the same money as a receipt.
    $matchable = array_values(array_filter(
        BankTransactionType::cases(),
        fn (BankTransactionType $type): bool => $type->isMatchable(),
    ));

    expect($matchable)->toBe([BankTransactionType::Spend, BankTransactionType::Receive]);
});

it('derives a direction for the two matchable types and nothing else', function () {
    expect(BankTransactionType::Spend->direction())->toBe(MoneyDirection::Out)
        ->and(BankTransactionType::Receive->direction())->toBe(MoneyDirection::In)
        ->and(BankTransactionType::SpendTransfer->direction())->toBeNull()
        ->and(BankTransactionType::ReceiveTransfer->direction())->toBeNull()
        ->and(BankTransactionType::SpendPrepayment->direction())->toBeNull()
        ->and(BankTransactionType::ReceivePrepayment->direction())->toBeNull()
        ->and(BankTransactionType::SpendOverpayment->direction())->toBeNull()
        ->and(BankTransactionType::ReceiveOverpayment->direction())->toBeNull();
});

it('keeps isSpend broad, which is exactly why it must not drive a candidate filter', function () {
    expect(BankTransactionType::SpendTransfer->isSpend())->toBeTrue()
        ->and(BankTransactionType::SpendTransfer->isMatchable())->toBeFalse();
});

it('maps a direction back to the type a create posts as', function () {
    expect(MoneyDirection::Out->bankTransactionType())->toBe(BankTransactionType::Spend)
        ->and(MoneyDirection::In->bankTransactionType())->toBe(BankTransactionType::Receive)
        ->and(MoneyDirection::Out->isOut())->toBeTrue()
        ->and(MoneyDirection::In->isIn())->toBeTrue()
        ->and(MoneyDirection::from('in'))->toBe(MoneyDirection::In);
});

it('still reads every Xero type off the wire case-insensitively', function () {
    foreach (BankTransactionType::cases() as $type) {
        expect(BankTransactionType::tryFromXero(strtolower($type->value)))->toBe($type);
    }

    expect(BankTransactionType::tryFromXero('SOMETHING-NEW'))->toBeNull();
});
