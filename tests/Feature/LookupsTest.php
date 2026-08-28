<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\AccountClass;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Support\ArrayLookupStore;

/**
 * The doc-derived chart-of-accounts response: one bank, one expense, one revenue.
 *
 * @return array<string, mixed>
 */
function accountsPayload(): array
{
    return providerResponse('xero/accounts');
}

it('serves bank accounts out of the chart of accounts, costing no extra call', function () {
    // AccountingPipe asked Xero separately for bank accounts and expense accounts.
    // That is two calls out of a per-minute budget of sixty the customer shares with
    // every other app they have connected.
    $fake = fakeHttp();
    $fake->queue(200, accountsPayload());

    $connector = xeroWithStore($fake);

    $all = $connector->chartOfAccounts(connection());
    $banks = $connector->bankAccounts(connection());

    expect($all)->toHaveCount(3)
        ->and($banks)->toHaveCount(1)
        ->and($banks[0]->name)->toBe('Business Checking')
        // One HTTP call served both questions.
        ->and($fake->requests)->toHaveCount(1);
});

it('asks Xero for every active account rather than one type at a time', function () {
    $fake = fakeHttp();
    $fake->queue(200, accountsPayload());

    xeroWithStore($fake)->chartOfAccounts(connection());

    expect(urldecode((string) $fake->requests[0]->getUri()))
        ->toContain('Status=="ACTIVE"')
        ->not->toContain('Type==');
});

it('classifies accounts so a host can filter without knowing either vocabulary', function () {
    $fake = fakeHttp();
    $fake->queue(200, accountsPayload());

    $accounts = xeroWithStore($fake)->chartOfAccounts(connection());

    expect(Account::only($accounts, AccountClass::Expense)[0]->lineReference())->toBe('400')
        ->and(Account::only($accounts, AccountClass::Revenue)[0]->name)->toBe('Consulting Income')
        // A bank account is still addressed by AccountID, not by its code.
        ->and(Account::only($accounts, AccountClass::Bank)[0]->lineReference())->toBe('bank-uuid');
});

it('writes a fetched list into the durable store', function () {
    $store = new ArrayLookupStore;
    $fake = fakeHttp();
    $fake->queue(200, accountsPayload());

    xeroWithStore($fake, $store)->chartOfAccounts(connection());

    expect($store->writes)->toBe(['chart_of_accounts'])
        ->and($store->get(connection(), 'chart_of_accounts'))->toHaveCount(3);
});

it('serves a stored list without calling the provider when the cache is cold', function () {
    // The deploy case: cache flushed, but the stored list survives, so a settings
    // page load does not send every organization back to Xero.
    $store = new ArrayLookupStore;
    $store->seed(connection(), 'chart_of_accounts', [
        ['id' => 'exp-uuid', 'name' => 'General Expenses', 'code' => '400', 'type' => 'EXPENSE', 'class' => 'expense'],
    ]);

    $fake = fakeHttp();

    $accounts = xeroWithStore($fake, $store)->chartOfAccounts(connection());

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->name)->toBe('General Expenses')
        ->and($accounts[0]->class)->toBe(AccountClass::Expense)
        ->and($fake->requests)->toBeEmpty();
});

it('serves a stale list when the provider is unreachable', function () {
    // A Xero outage should leave a settings page slightly stale, not empty. An empty
    // account dropdown reads to a customer as "my chart of accounts is gone".
    $store = new ArrayLookupStore;
    $store->seed(connection(), 'chart_of_accounts', [
        ['id' => 'exp-uuid', 'name' => 'General Expenses', 'code' => '400', 'type' => 'EXPENSE', 'class' => 'expense'],
    ]);

    $fake = fakeHttp();
    $fake->queue(503, []);

    $accounts = xeroWithStore($fake, $store)->chartOfAccounts(connection(), forceRefresh: true);

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->name)->toBe('General Expenses')
        ->and($fake->requests)->toHaveCount(1);
});

it('propagates the error when the provider fails and nothing was ever stored', function () {
    // Nothing honest to show, so do not pretend the chart of accounts is empty.
    $fake = fakeHttp();
    $fake->queue(503, []);

    expect(fn () => xeroWithStore($fake)->chartOfAccounts(connection()))
        ->toThrow(ServerException::class);
});

it('goes back to the provider when a refresh is forced', function () {
    $store = new ArrayLookupStore;
    $store->seed(connection(), 'chart_of_accounts', [
        ['id' => 'old', 'name' => 'Stale Account', 'class' => 'expense'],
    ]);

    $fake = fakeHttp();
    $fake->queue(200, accountsPayload());

    $accounts = xeroWithStore($fake, $store)->chartOfAccounts(connection(), forceRefresh: true);

    expect($accounts)->toHaveCount(3)
        ->and($fake->requests)->toHaveCount(1);
});

it('scopes stored lookups per tenant', function () {
    $store = new ArrayLookupStore;
    $store->seed(connection(), 'chart_of_accounts', [['id' => 'a', 'name' => 'Tenant A account']]);

    $other = new Connection(
        Provider::Xero,
        'tenant-2',
        'access-token',
        expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
    );

    expect($store->get($other, 'chart_of_accounts'))->toBeNull();
});

it('reads tax rates with their portable sales and purchase flags', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/tax-rates'));

    $codes = xeroWithStore($fake)->taxCodes(connection());

    // Sorted by name: Tax Exempt, Tax on Purchases, Tax on Sales.
    expect($codes)->toHaveCount(3)
        ->and($codes[0]->reference)->toBe('NONE')
        ->and($codes[1]->reference)->toBe('INPUT2')
        ->and($codes[1]->rate)->toBe(15.0)
        ->and($codes[1]->isPurchaseTax)->toBeTrue()
        ->and($codes[1]->isSalesTax)->toBeFalse()
        ->and($codes[2]->reference)->toBe('OUTPUT2')
        ->and($codes[2]->isSalesTax)->toBeTrue();
});

it('refreshes every Xero lookup on request', function () {
    $fake = fakeHttp();
    $fake->queue(200, accountsPayload());
    $fake->queue(200, ['TaxRates' => [['TaxType' => 'NONE', 'Name' => 'Tax Exempt']]]);
    $fake->queue(200, ['TrackingCategories' => []]);

    xeroWithStore($fake)->refreshLookups(connection());

    expect($fake->requests)->toHaveCount(3)
        ->and($fake->isDrained())->toBeTrue();
});
