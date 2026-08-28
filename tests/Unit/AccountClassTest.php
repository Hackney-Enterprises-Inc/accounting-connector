<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Enums\AccountClass;

it('folds every Xero expense-shaped type into one class', function () {
    // Xero splits expenses three ways and a host building a "default expense
    // account" dropdown should not have to know that.
    expect(AccountClass::fromXero('EXPENSE'))->toBe(AccountClass::Expense)
        ->and(AccountClass::fromXero('DIRECTCOSTS'))->toBe(AccountClass::Expense)
        ->and(AccountClass::fromXero('OVERHEADS'))->toBe(AccountClass::Expense);
});

it('classifies the rest of the Xero vocabulary', function () {
    expect(AccountClass::fromXero('BANK'))->toBe(AccountClass::Bank)
        ->and(AccountClass::fromXero('REVENUE'))->toBe(AccountClass::Revenue)
        ->and(AccountClass::fromXero('SALES'))->toBe(AccountClass::Revenue)
        ->and(AccountClass::fromXero('CURRLIAB'))->toBe(AccountClass::Liability)
        ->and(AccountClass::fromXero('FIXED'))->toBe(AccountClass::Asset)
        ->and(AccountClass::fromXero('EQUITY'))->toBe(AccountClass::Equity);
});

it('folds every QuickBooks expense-shaped type into one class', function () {
    expect(AccountClass::fromQuickBooks('Expense'))->toBe(AccountClass::Expense)
        ->and(AccountClass::fromQuickBooks('Other Expense'))->toBe(AccountClass::Expense)
        ->and(AccountClass::fromQuickBooks('Cost of Goods Sold'))->toBe(AccountClass::Expense);
});

it('treats a QuickBooks credit card as spendable, not as a liability', function () {
    // It is a liability on the balance sheet, but every question this enum gets
    // asked about it is "can money be spent from here". AccountingPipe's own
    // payment-account lookup makes the same call.
    expect(AccountClass::fromQuickBooks('Credit Card'))->toBe(AccountClass::Bank)
        ->and(AccountClass::fromQuickBooks('Bank'))->toBe(AccountClass::Bank);
});

it('classifies the rest of the QuickBooks vocabulary', function () {
    expect(AccountClass::fromQuickBooks('Income'))->toBe(AccountClass::Revenue)
        ->and(AccountClass::fromQuickBooks('Accounts Payable'))->toBe(AccountClass::Liability)
        ->and(AccountClass::fromQuickBooks('Fixed Asset'))->toBe(AccountClass::Asset)
        ->and(AccountClass::fromQuickBooks('Equity'))->toBe(AccountClass::Equity);
});

it('falls back to Other rather than guessing at something unrecognised', function () {
    expect(AccountClass::fromXero('SOMETHING_NEW'))->toBe(AccountClass::Other)
        ->and(AccountClass::fromXero(null))->toBe(AccountClass::Other)
        ->and(AccountClass::fromQuickBooks('Something New'))->toBe(AccountClass::Other)
        ->and(AccountClass::fromQuickBooks(null))->toBe(AccountClass::Other);
});

it('is case-insensitive on the Xero side', function () {
    expect(AccountClass::fromXero('bank'))->toBe(AccountClass::Bank);
});

it('classifies both spellings of the PAYG liability', function () {
    // The OpenAPI spec says PAYG; production tenants have also returned the longer
    // PAYGLIABILITY. Both are liabilities.
    expect(AccountClass::fromXero('PAYG'))->toBe(AccountClass::Liability)
        ->and(AccountClass::fromXero('PAYGLIABILITY'))->toBe(AccountClass::Liability);
});

it('narrows a mixed account list to one classification', function () {
    $accounts = [
        new Account('1', 'Checking', type: 'BANK', class: AccountClass::Bank),
        new Account('2', 'Office Supplies', code: '400', type: 'EXPENSE', class: AccountClass::Expense),
        new Account('3', 'Consulting Income', code: '200', type: 'REVENUE', class: AccountClass::Revenue),
    ];

    expect(Account::only($accounts, AccountClass::Bank))->toHaveCount(1)
        ->and(Account::only($accounts, AccountClass::Expense)[0]->name)->toBe('Office Supplies')
        ->and(Account::only($accounts, AccountClass::Equity))->toBeEmpty();
});

it('answers what a line can be coded to', function () {
    $expense = new Account('2', 'Office Supplies', class: AccountClass::Expense);

    expect($expense->isExpense())->toBeTrue()
        ->and($expense->isBank())->toBeFalse()
        ->and($expense->isRevenue())->toBeFalse()
        ->and($expense->class->isSpendable())->toBeTrue();
});

it('round-trips an account through the stored array shape', function () {
    $account = new Account('1', 'Checking', code: '090', type: 'BANK', class: AccountClass::Bank, reference: '1');

    $restored = Account::fromArray($account->toArray());

    expect($restored->class)->toBe(AccountClass::Bank)
        ->and($restored->lineReference())->toBe('1')
        ->and($restored->code)->toBe('090');
});
