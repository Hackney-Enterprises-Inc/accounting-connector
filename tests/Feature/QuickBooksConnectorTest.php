<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\QuickBooks\QuickBooksConnector;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\InvoiceData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\JournalLine;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Data\PaymentData;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Enums\AccountClass;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\LineAmountType;
use Hei\AccountingConnector\Enums\MoneyDirection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Support\ArrayEntityMap;
use Hei\AccountingConnector\Support\NullConnectionStore;
use Hei\AccountingConnector\Testing\FakeHttpClient;

function qbo(FakeHttpClient $fake, ?ArrayEntityMap $map = null, ?ConnectionStore $store = null): QuickBooksConnector
{
    return new QuickBooksConnector(
        http: httpClientOver($fake),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        connections: $store ?? new NullConnectionStore,
        entityMap: $map ?? new ArrayEntityMap,
    );
}

function qboBill(): BillData
{
    return new BillData(
        vendor: 'Acme Supply',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Widgets', unitAmount: Money::cents(2500), quantity: 3, accountCode: '63')],
        localId: 'doc-42',
    );
}

it('scopes every url to the connection realm and pins the minor version', function () {
    // The realm always comes from the Connection and never from a request. That is
    // what keeps one customer's books out of another's.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    $fake->queue(200, ['Bill' => ['Id' => '101']]);

    qbo($fake)->createEntity(EntityType::Bill, qboBill(), qboConnection());

    expect((string) $fake->requests[1]->getUri())
        ->toContain('/v3/company/realm-1/bill')
        ->toContain('minorversion=75');
});

it('sends the idempotency key as a requestid query parameter, not a header', function () {
    // Intuit differs from Xero here, and a duplicate requestid does not error: it
    // silently replays the original response.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    $fake->queue(200, ['Bill' => ['Id' => '101']]);

    qbo($fake)->createEntity(EntityType::Bill, qboBill(), qboConnection(), 'sync-bill-doc-42');

    expect((string) $fake->requests[1]->getUri())->toContain('requestid=sync-bill-doc-42')
        ->and($fake->requests[1]->getHeaderLine('Idempotency-Key'))->toBe('');
});

it('folds quantity into the line amount, because account-based lines have no quantity', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    // The full doc-derived envelope, so id extraction is proven against the noise a
    // real response carries rather than a minimal stub.
    $fake->queue(200, providerResponse('quickbooks/bill-created'));

    $id = qbo($fake)->createEntity(EntityType::Bill, qboBill(), qboConnection());

    $line = $fake->requestBody(1)['Line'][0];

    expect($id)->toBe('101')
        ->and($line['DetailType'])->toBe('AccountBasedExpenseLineDetail')
        ->and((float) $line['Amount'])->toBe(75.0)
        ->and($line['AccountBasedExpenseLineDetail']['AccountRef'])->toBe(['value' => '63']);
});

it('posts an expense as a Purchase against the payment account', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    $fake->queue(200, ['Purchase' => ['Id' => '202']]);

    $expense = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Coffee', unitAmount: Money::cents(1250), accountCode: '63')],
        bankAccount: '35',
        paymentMethod: 'CreditCard',
    );

    $id = qbo($fake)->createEntity(EntityType::Expense, $expense, qboConnection());

    $body = $fake->requestBody(1);

    expect($id)->toBe('202')
        ->and((string) $fake->requests[1]->getUri())->toContain('/purchase')
        ->and($body['AccountRef'])->toBe(['value' => '35'])
        ->and($body['PaymentType'])->toBe('CreditCard')
        ->and($body['EntityRef'])->toBe(['value' => '7', 'type' => 'Vendor']);
});

it('refuses a money-in expense rather than posting a refund as a purchase', function () {
    // A Purchase is money out by definition. Until a Deposit mapping exists the
    // honest answer is a refusal before any call, not a second outgoing entry.
    $fake = fakeHttp();

    $refund = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Refund', unitAmount: Money::cents(1250), accountCode: '63')],
        bankAccount: '35',
        paymentMethod: 'CreditCard',
        direction: MoneyDirection::In,
    );

    expect(fn () => qbo($fake)->createEntity(EntityType::Expense, $refund, qboConnection()))
        ->toThrow(InvalidPayloadException::class, 'money-in');

    expect($fake->requests)->toBeEmpty();
});

it('refuses a money-in expense on an update as it does on a create', function () {
    // The update path builds the same Purchase; it must refuse the same payload
    // before reading the SyncToken or sending anything.
    $fake = fakeHttp();

    $refund = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Refund', unitAmount: Money::cents(1250), accountCode: '63')],
        bankAccount: '35',
        paymentMethod: 'CreditCard',
        direction: MoneyDirection::In,
    );

    expect(fn () => qbo($fake)->updateEntity(EntityType::Expense, '101', $refund, qboConnection()))
        ->toThrow(InvalidPayloadException::class, 'money-in');

    expect($fake->requests)->toBeEmpty();
});

it('sends the tax mode as GlobalTaxCalculation on every document', function () {
    // Required on non-US companies, ignored by US ones. Omitting it made Intuit
    // treat tax-inclusive amounts as exclusive and post totals off by the tax.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    $fake->queue(200, ['Bill' => ['Id' => '101']]);

    qbo($fake)->createEntity(EntityType::Bill, qboBill(), qboConnection());

    expect($fake->requestBody(1)['GlobalTaxCalculation'])->toBe('TaxExcluded');
});

it('marks tax-inclusive amounts as TaxInclusive rather than letting Intuit assume', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Customer' => [['Id' => '12']]]]);
    $fake->queue(200, ['Invoice' => ['Id' => '303']]);

    $invoice = new InvoiceData(
        customer: 'Northwind Ltd',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Consulting', unitAmount: Money::cents(15000))],
        lineAmountType: LineAmountType::Inclusive,
    );

    qbo($fake)->createEntity(EntityType::Invoice, $invoice, qboConnection());

    expect($fake->requestBody(1)['GlobalTaxCalculation'])->toBe('TaxInclusive');
});

it('refuses a PaymentType Intuit does not recognise before making any call', function () {
    // Intuit's own refusal is a generic validation fault that names neither the
    // field nor the allowed values.
    $fake = fakeHttp();

    $expense = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Coffee', unitAmount: Money::cents(1250))],
        bankAccount: '35',
        paymentMethod: 'Visa',
    );

    expect(fn () => qbo($fake)->createEntity(EntityType::Expense, $expense, qboConnection()))
        ->toThrow(InvalidPayloadException::class, 'Cash, Check or CreditCard');

    expect($fake->requests)->toBeEmpty();
});

it('refuses a purchase with no payment account before making any call', function () {
    $fake = fakeHttp();

    $expense = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Coffee', unitAmount: Money::cents(1250))],
    );

    expect(fn () => qbo($fake)->createEntity(EntityType::Expense, $expense, qboConnection()))
        ->toThrow(InvalidPayloadException::class);
});

it('posts an invoice line through the item wired to its income account', function () {
    // QuickBooks sales lines are item-based: ItemAccountRef is ignored on create
    // (verified live — the line quietly attaches to the company's default item and
    // posts income to the wrong account), so the income account must travel via an
    // ItemRef resolved from the item list.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Customer' => [['Id' => '12']]]]);
    $fake->queue(200, providerResponse('quickbooks/item-query'));
    $fake->queue(200, ['Invoice' => ['Id' => '303']]);

    $invoice = new InvoiceData(
        customer: 'Northwind Ltd',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Consulting', unitAmount: Money::cents(15000), quantity: 2, accountCode: '82')],
        documentNumber: 'INV-1001',
    );

    $id = qbo($fake)->createEntity(EntityType::Invoice, $invoice, qboConnection());

    $body = $fake->requestBody(2);

    expect($id)->toBe('303')
        ->and($body['CustomerRef'])->toBe(['value' => '12'])
        ->and($body['DocNumber'])->toBe('INV-1001')
        ->and($body['Line'][0]['DetailType'])->toBe('SalesItemLineDetail')
        ->and((float) $body['Line'][0]['Amount'])->toBe(300.0)
        // Account 82 resolves to the real sandbox's "Design" item.
        ->and($body['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => '4'])
        ->and($body['Line'][0]['SalesItemLineDetail'])->not->toHaveKey('ItemAccountRef');
});

it('creates a service item when no item serves the income account', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Customer' => [['Id' => '12']]]]);
    $fake->queue(200, providerResponse('quickbooks/item-query'));      // no item for account 79
    $fake->queue(200, providerResponse('quickbooks/account-query'));   // names account 79
    $fake->queue(200, providerResponse('quickbooks/item-created'));
    $fake->queue(200, providerResponse('quickbooks/item-query'));      // write-through refresh
    $fake->queue(200, ['Invoice' => ['Id' => '303']]);

    $invoice = new InvoiceData(
        customer: 'Northwind Ltd',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Consulting', unitAmount: Money::cents(15000), accountCode: '79')],
    );

    qbo($fake)->createEntity(EntityType::Invoice, $invoice, qboConnection());

    expect($fake->requestBody(3))->toBe([
        'Name' => 'Consulting Income',
        'Type' => 'Service',
        'IncomeAccountRef' => ['value' => '79'],
    ])->and($fake->requestBody(5)['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => '19']);
});

it('falls back to a suffixed item name when the account name is taken', function () {
    // A 6240 duplicate-name fault means an item with this name exists but is wired
    // to a different income account, or a racing worker just created it. Rescan
    // first, then try the suffixed name.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Customer' => [['Id' => '12']]]]);
    $fake->queue(200, providerResponse('quickbooks/item-query'));
    $fake->queue(200, providerResponse('quickbooks/account-query'));
    $fake->queue(400, providerResponse('quickbooks/item-duplicate-fault'));
    $fake->queue(200, providerResponse('quickbooks/item-query'));      // rescan: still nothing for 79
    $fake->queue(200, providerResponse('quickbooks/item-created'));
    $fake->queue(200, providerResponse('quickbooks/item-query'));      // write-through refresh
    $fake->queue(200, ['Invoice' => ['Id' => '303']]);

    $invoice = new InvoiceData(
        customer: 'Northwind Ltd',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Consulting', unitAmount: Money::cents(15000), accountCode: '79')],
    );

    qbo($fake)->createEntity(EntityType::Invoice, $invoice, qboConnection());

    expect($fake->requestBody(5)['Name'])->toBe('Consulting Income (79)')
        ->and($fake->requestBody(7)['Line'][0]['SalesItemLineDetail']['ItemRef'])->toBe(['value' => '19'])
        ->and($fake->isDrained())->toBeTrue();
});

it('looks a customer up in the Customer resource, not the Vendor one', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Customer' => [['Id' => '12']]]]);
    $fake->queue(200, ['Invoice' => ['Id' => '303']]);

    $invoice = new InvoiceData(
        customer: 'Northwind Ltd',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Consulting', unitAmount: Money::cents(15000))],
    );

    qbo($fake)->createEntity(EntityType::Invoice, $invoice, qboConnection());

    expect(urldecode((string) $fake->requests[0]->getUri()))->toContain('from Customer where DisplayName');
});

it('escapes a quote in a vendor name so the query language does not break', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => []]);
    $fake->queue(200, ['Vendor' => ['Id' => '9']]);
    $fake->queue(200, ['Bill' => ['Id' => '101']]);

    $bill = new BillData(
        vendor: "Bob's Plumbing",
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Repair', unitAmount: Money::cents(9900), accountCode: '63')],
    );

    qbo($fake)->createEntity(EntityType::Bill, $bill, qboConnection());

    expect(urldecode((string) $fake->requests[0]->getUri()))->toContain("Bob\\'s Plumbing");
});

it('signs journal lines into explicit debit and credit postings', function () {
    // Canonical journal lines are signed, Xero-style. QuickBooks wants an explicit
    // PostingType and an unsigned amount.
    $fake = fakeHttp();
    $fake->queue(200, ['JournalEntry' => ['Id' => '404']]);

    $journal = new JournalData(
        narration: 'Payout attribution',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [
            new JournalLine('63', Money::cents(10000), 'Gross'),
            new JournalLine('90', Money::cents(-10000), 'Clearing'),
        ],
    );

    qbo($fake)->createEntity(EntityType::Journal, $journal, qboConnection());

    $lines = $fake->requestBody(0)['Line'];

    expect($lines[0]['JournalEntryLineDetail']['PostingType'])->toBe('Debit')
        ->and((float) $lines[0]['Amount'])->toBe(100.0)
        ->and($lines[1]['JournalEntryLineDetail']['PostingType'])->toBe('Credit')
        ->and((float) $lines[1]['Amount'])->toBe(100.0);
});

it('refuses a payment with no customer, which QuickBooks requires', function () {
    // Xero attaches a payment to an invoice alone; QuickBooks needs the customer too.
    $payment = new PaymentData(
        invoiceExternalId: '303',
        amount: Money::cents(30000),
        date: new DateTimeImmutable('2026-08-21'),
        account: '35',
    );

    expect(fn () => qbo(fakeHttp())->createEntity(EntityType::Payment, $payment, qboConnection()))
        ->toThrow(InvalidPayloadException::class, 'needs a customer as well as an invoice id');
});

it('links a payment to its invoice', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Customer' => [['Id' => '12']]]]);
    $fake->queue(200, ['Payment' => ['Id' => '505']]);

    $payment = new PaymentData(
        invoiceExternalId: '303',
        amount: Money::cents(30000),
        date: new DateTimeImmutable('2026-08-21'),
        account: '35',
        customer: ContactData::customer('Northwind Ltd'),
    );

    qbo($fake)->createEntity(EntityType::Payment, $payment, qboConnection());

    $body = $fake->requestBody(1);

    expect($body['Line'][0]['LinkedTxn'][0])->toBe(['TxnId' => '303', 'TxnType' => 'Invoice'])
        ->and($body['DepositToAccountRef'])->toBe(['value' => '35']);
});

it('reads the SyncToken before updating, because Intuit demands the current one', function () {
    // A stale SyncToken is a 400, and guessing zero silently clobbers a concurrent
    // edit made in the QuickBooks UI.
    $fake = fakeHttp();
    $fake->queue(200, ['Bill' => ['Id' => '101', 'SyncToken' => '4']]);
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    $fake->queue(200, ['Bill' => ['Id' => '101']]);

    qbo($fake)->updateEntity(EntityType::Bill, '101', qboBill(), qboConnection());

    expect($fake->requests[0]->getMethod())->toBe('GET')
        ->and($fake->requestBody(2)['SyncToken'])->toBe('4')
        ->and($fake->requestBody(2)['Id'])->toBe('101');
});

it('uploads an attachment as multipart with Intuit two named parts', function () {
    // The part names are literal and the numeric suffix pairs them. Renaming either
    // is a 400 that does not say why.
    $fake = fakeHttp();
    $fake->queue(200, ['AttachableResponse' => [['Attachable' => ['Id' => 'att-1']]]]);

    $result = qbo($fake)->attach(
        EntityType::Bill,
        '101',
        new Attachment('receipt', 'PDF-BYTES', 'application/pdf'),
        qboConnection(),
    );

    $body = $fake->bodies[0];

    expect($result->uploaded)->toBeTrue()
        ->and($result->externalId)->toBe('att-1')
        ->and($fake->requests[0]->getHeaderLine('Content-Type'))->toStartWith('multipart/form-data; boundary=')
        ->and($body)->toContain('name="file_metadata_01"')
        ->and($body)->toContain('name="file_content_01"')
        ->and($body)->toContain('"type":"Bill"')
        ->and($body)->toContain('PDF-BYTES');
});

it('maps an expense onto the Purchase attachable type, not Expense', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['AttachableResponse' => [['Attachable' => ['Id' => 'att-1']]]]);

    qbo($fake)->attach(
        EntityType::Expense,
        '202',
        new Attachment('receipt.pdf', 'BYTES', 'application/pdf'),
        qboConnection(),
    );

    expect($fake->bodies[0])->toContain('"type":"Purchase"');
});

it('reports rather than throws when Intuit rejects the attachment', function () {
    $fake = fakeHttp();
    $fake->queue(400, ['Fault' => ['Error' => [['Message' => 'Invalid file', 'Detail' => 'too weird']]]]);

    $result = qbo($fake)->attach(
        EntityType::Bill,
        '101',
        new Attachment('receipt.pdf', 'BYTES', 'application/pdf'),
        qboConnection(),
    );

    expect($result->uploaded)->toBeFalse()
        ->and($result->reason)->toBe('Invalid file: too weird');
});

it('surfaces the Intuit fault message and nothing from the request', function () {
    // A bill payload carries vendor names and amounts. Neither belongs in a log line.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    $fake->queue(400, ['Fault' => ['Error' => [[
        'Message' => 'Invalid Reference Id',
        'Detail' => 'Accounts element id 999 not found',
    ]]]]);

    expect(fn () => qbo($fake)->createEntity(EntityType::Bill, qboBill(), qboConnection()))
        ->toThrow(ValidationException::class, 'Invalid Reference Id: Accounts element id 999 not found');
});

it('persists the rotated refresh token before the new access token is used', function () {
    // Intuit rotates on every refresh. Missing one write kills the connection days
    // later with no obvious cause, so this is the single most important write here.
    $persisted = [];
    $store = new class($persisted) implements ConnectionStore
    {
        public function __construct(public array &$seen) {}

        public function persist(Connection $connection): void
        {
            $this->seen[] = $connection;
        }
    };

    $fake = fakeHttp();
    $fake->queue(200, [
        'access_token' => 'fresh-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 3600,
        'x_refresh_token_expires_in' => 8726400,
    ]);

    $refreshed = qbo($fake, null, $store)->refresh(qboConnection());

    expect($refreshed->accessToken)->toBe('fresh-access')
        ->and($refreshed->refreshToken)->toBe('rotated-refresh')
        ->and($persisted)->toHaveCount(1)
        ->and($persisted[0]->refreshToken)->toBe('rotated-refresh');
});

it('carries the old refresh token forward when Intuit omits a new one', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['access_token' => 'fresh-access', 'expires_in' => 3600]);

    expect(qbo($fake)->refresh(qboConnection())->refreshToken)->toBe('refresh-token');
});

it('treats a rejected refresh as a revocation', function () {
    $fake = fakeHttp();
    $fake->queue(400, ['error' => 'invalid_grant']);

    expect(fn () => qbo($fake)->refresh(qboConnection()))
        ->toThrow(ConnectionRevokedException::class);
});

it('takes the realm id from the callback query, where Intuit puts it', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]);

    $result = qbo($fake)->exchangeCode('the-code', ['realmId' => '9130350000']);

    expect($result->tenantId)->toBe('9130350000')
        ->and($result->toConnection()->tenantId)->toBe('9130350000');
});

it('hydrates a realistic chart of accounts, credit cards included as spendable', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('quickbooks/account-query'));

    $accounts = qbo($fake)->chartOfAccounts(qboConnection());
    $banks = Account::only($accounts, AccountClass::Bank);

    // Sorted by name: Checking, Consulting Income, Office Supplies, Visa. The Visa
    // credit card classifies as Bank because every question this list gets asked
    // about it is "can money be spent from here".
    expect($accounts)->toHaveCount(4)
        ->and($accounts[0]->name)->toBe('Checking')
        ->and($banks)->toHaveCount(2)
        ->and($accounts[2]->lineReference())->toBe('63')
        ->and($accounts[2]->code)->toBe('6000');
});

it('reads tax codes from a realistic query response', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('quickbooks/taxcode-query'));

    $codes = qbo($fake)->taxCodes(qboConnection());

    // Sorted by name: NON, TAX. The reference is the TaxCodeRef id, not the name.
    expect($codes)->toHaveCount(2)
        ->and($codes[0]->reference)->toBe('3')
        ->and($codes[1]->reference)->toBe('2')
        ->and($codes[1]->isSalesTax)->toBeTrue();
});

it('reads the company back for confirmation after connecting', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('quickbooks/companyinfo'));

    $info = qbo($fake)->tenantInfo(qboConnection());

    expect($info?->id)->toBe('realm-1')
        ->and($info?->name)->toBe('Sandbox Company_US_1')
        ->and($info?->legalName)->toBe('Sandbox Company_US_1 LLC')
        ->and($info?->countryCode)->toBe('US')
        // CompanyInfo carries no currency element, so this degrades to null rather
        // than guessing. See .claude/docs/04-provider-response-shapes.md.
        ->and($info?->currencyCode)->toBeNull();
});

it('reads accounts back addressed by id, never by account number', function () {
    // QuickBooks line items never address an account by AcctNum, even when the
    // company has assigned one.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Account' => [
        ['Id' => '63', 'Name' => 'Office Supplies', 'AcctNum' => '6000', 'AccountType' => 'Expense'],
    ]]]);

    $accounts = qbo($fake)->chartOfAccounts(qboConnection());

    expect($accounts[0]->lineReference())->toBe('63')
        ->and($accounts[0]->code)->toBe('6000');
});

it('resolves a VendorName inside a raw payload, so a straight port keeps working', function () {
    // AccountingPipe's existing QuickBooksMapper emits a plain VendorName.
    $fake = fakeHttp();
    $fake->queue(200, ['QueryResponse' => ['Vendor' => [['Id' => '7']]]]);
    $fake->queue(200, ['Bill' => ['Id' => '101']]);

    $raw = RawPayload::forQuickBooks(EntityType::Bill, [
        'VendorName' => 'Acme Supply',
        'TxnDate' => '2026-08-21',
        'Line' => [['DetailType' => 'AccountBasedExpenseLineDetail', 'Amount' => 75.0]],
    ]);

    $id = qbo($fake)->createEntity(EntityType::Bill, $raw, qboConnection());

    expect($id)->toBe('101')
        ->and($fake->requestBody(1)['VendorRef'])->toBe(['value' => '7', 'name' => 'Acme Supply'])
        ->and($fake->requestBody(1))->not->toHaveKey('VendorName');
});

it('points at the sandbox when told to', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['CompanyInfo' => ['CompanyName' => 'Sandbox Co']]);

    qbo($fake)->usingBaseUrl(QuickBooksConnector::SANDBOX_BASE)->tenantInfo(qboConnection());

    expect($fake->requests[0]->getUri()->getHost())->toBe('sandbox-quickbooks.api.intuit.com');
});

it('is the QuickBooks provider', function () {
    expect(qbo(fakeHttp())->provider())->toBe(Provider::QuickBooksOnline);
});
