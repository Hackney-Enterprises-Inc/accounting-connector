<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentSet;
use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\JournalLine;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\TrackingRef;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\AuthenticationException;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Support\ArrayEntityMap;
use Hei\AccountingConnector\Testing\FakeHttpClient;

function xero(FakeHttpClient $fake, ?ArrayEntityMap $map = null): XeroConnector
{
    return new XeroConnector(
        http: httpClientOver($fake),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        entityMap: $map ?? new ArrayEntityMap,
    );
}

it('sends the tenant header and asks for JSON on every call', function () {
    // The Accounting API answers in XML without the Accept header, which is the
    // single easiest way to spend an afternoon debugging an empty response.
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xero($fake)->createEntity(EntityType::Bill, billFor(), connection(), 'sync-bill-doc-42');

    $request = $fake->requests[0];

    expect($request->getHeaderLine('xero-tenant-id'))->toBe('tenant-1')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer access-token');
});

it('sends the idempotency key as a header on a create', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xero($fake)->createEntity(EntityType::Bill, billFor(), connection(), 'sync-bill-doc-42');

    expect($fake->requests[1]->getHeaderLine('Idempotency-Key'))->toBe('sync-bill-doc-42');
});

it('posts a bill as an ACCPAY invoice with quantity and unit amount', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    // The full doc-derived envelope, so id extraction is proven against the noise a
    // real response carries rather than a minimal stub.
    $fake->queue(200, providerResponse('xero/invoice-created'));

    $id = xero($fake)->createEntity(EntityType::Bill, billFor(), connection());

    $body = $fake->requestBody(1)['Invoices'][0];

    expect($id)->toBe('invoice-1')
        ->and($body['Type'])->toBe('ACCPAY')
        ->and($body['Contact'])->toBe(['ContactID' => 'contact-1'])
        ->and($body['Date'])->toBe('2026-08-21')
        ->and($body['Status'])->toBe('DRAFT')
        // PHP encodes a whole float as a JSON integer, so 25.0 goes over the wire
        // as 25. JSON has one number type and Xero parses either to the same
        // decimal, so this is compared numerically rather than by PHP type.
        ->and((float) $body['LineItems'][0]['Quantity'])->toBe(3.0)
        ->and((float) $body['LineItems'][0]['UnitAmount'])->toBe(25.0)
        ->and($body['LineItems'][0]['AccountCode'])->toBe('400')
        ->and($body['LineItems'][0])->not->toHaveKey('LineAmount');
});

it('posts a flat-amount line as LineAmount and never alongside a quantity', function () {
    // Sending Quantity, UnitAmount and LineAmount together makes Xero recalculate
    // the line from the first two and discard the third, which posts an allocation
    // split at the wrong amount.
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    $bill = new BillData(
        vendor: 'Acme Supply',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem(
            description: 'Allocated share',
            lineAmount: Money::cents(3333),
            accountCode: '400',
        )],
    );

    xero($fake)->createEntity(EntityType::Bill, $bill, connection());

    $line = $fake->requestBody(1)['Invoices'][0]['LineItems'][0];

    expect($line['LineAmount'])->toBe(33.33)
        ->and($line)->not->toHaveKey('Quantity')
        ->and($line)->not->toHaveKey('UnitAmount');
});

it('posts an expense as a SPEND bank transaction against the bank account', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['BankTransactions' => [['BankTransactionID' => 'txn-1']]]);

    $expense = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Coffee', unitAmount: Money::cents(1250), accountCode: '400')],
        bankAccount: 'bank-account-1',
    );

    $id = xero($fake)->createEntity(EntityType::Expense, $expense, connection());

    $body = $fake->requestBody(1)['BankTransactions'][0];

    expect($id)->toBe('txn-1')
        ->and($body['Type'])->toBe('SPEND')
        ->and($body['BankAccount'])->toBe(['AccountID' => 'bank-account-1'])
        ->and($body['Status'])->toBe('AUTHORISED');
});

it('falls back to the connection bank account when the payload has none', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['BankTransactions' => [['BankTransactionID' => 'txn-1']]]);

    $expense = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Coffee', unitAmount: Money::cents(1250))],
    );

    xero($fake)->createEntity($e = EntityType::Expense, $expense, connection(settings: ['bank_account' => 'default-bank']));

    expect($fake->requestBody(1)['BankTransactions'][0]['BankAccount'])
        ->toBe(['AccountID' => 'default-bank']);
});

it('refuses a spend-money transaction with no bank account before making any call', function () {
    // Xero's own refusal is a generic validation error that never names the missing
    // field, so failing here saves a round trip and produces a usable message.
    $fake = fakeHttp();

    $expense = new ExpenseData(
        vendor: 'Corner Store',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem('Coffee', unitAmount: Money::cents(1250))],
    );

    expect(fn () => xero($fake)->createEntity(EntityType::Expense, $expense, connection()))
        ->toThrow(InvalidPayloadException::class);

    expect($fake->requests)->toBeEmpty();
});

it('carries tracking categories onto the line', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    $bill = new BillData(
        vendor: 'Acme Supply',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem(
            description: 'Widgets',
            unitAmount: Money::cents(2500),
            accountCode: '400',
            tracking: [new TrackingRef('cat-1', 'opt-1', 'Region', 'North')],
        )],
    );

    xero($fake)->createEntity(EntityType::Bill, $bill, connection());

    expect($fake->requestBody(1)['Invoices'][0]['LineItems'][0]['Tracking'][0])->toBe([
        'TrackingCategoryID' => 'cat-1',
        'TrackingOptionID' => 'opt-1',
        'Name' => 'Region',
        'Option' => 'North',
    ]);
});

it('reuses a mapped contact instead of querying Xero again', function () {
    // AccountingPipe queried Xero by name on every single post. That is a wasted
    // round trip against a 60-per-minute ceiling, and it matches on a name the
    // customer may have edited since.
    $map = new ArrayEntityMap;
    $map->remember(connection(), EntityType::Vendor, 'name:acme supply', 'contact-cached');

    $fake = fakeHttp();
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xero($fake, $map)->createEntity(EntityType::Bill, billFor(), connection());

    expect($fake->requests)->toHaveCount(1)
        ->and($fake->requestBody(0)['Invoices'][0]['Contact'])->toBe(['ContactID' => 'contact-cached']);
});

it('creates a contact Xero has never seen and remembers it', function () {
    $map = new ArrayEntityMap;
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => []]);
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-new']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xero($fake, $map)->createEntity(EntityType::Bill, billFor(), connection());

    expect($map->externalId(connection(), EntityType::Vendor, 'name:acme supply'))->toBe('contact-new')
        ->and($fake->requestBody(1)['Contacts'][0]['IsSupplier'])->toBeTrue();
});

it('skips the name lookup for a vendor whose name would break the where clause', function () {
    // Xero's where clause is a string expression with no escape syntax, so a double
    // quote in a vendor name terminates it early and matches the wrong contact.
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-new']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xero($fake)->createEntity(EntityType::Bill, billFor('The "Best" Shop'), connection());

    expect($fake->requests)->toHaveCount(2)
        ->and($fake->requests[0]->getMethod())->toBe('POST');
});

it('records the entity map entry under the local id after a successful post', function () {
    $map = new ArrayEntityMap;
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xero($fake, $map)->createEntity(EntityType::Bill, billFor(), connection());

    expect($map->externalId(connection(), EntityType::Bill, 'doc-42'))->toBe('invoice-1');
});

it('posts a manual journal as POSTED, which is the only word Xero takes there', function () {
    // Manual journals have their own status vocabulary: AUTHORISED, correct
    // everywhere else, is a 400 here.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/manual-journal-created'));

    $journal = new JournalData(
        narration: 'Payout 2026-08-21',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [
            new JournalLine('200', Money::cents(10000), 'Gross'),
            new JournalLine('404', Money::cents(-10000), 'Clearing'),
        ],
        localId: 'payout-7',
    );

    $id = xero($fake)->createEntity(EntityType::Journal, $journal, connection(), 'sync-journal-payout-7');

    $body = $fake->requestBody(0)['ManualJournals'][0];

    expect($id)->toBe('journal-1')
        ->and($fake->requests[0]->getMethod())->toBe('POST')
        ->and((string) $fake->requests[0]->getUri())->toBe(XeroConnector::API_BASE.'/ManualJournals')
        ->and($fake->requests[0]->getHeaderLine('Idempotency-Key'))->toBe('sync-journal-payout-7')
        ->and($body['Status'])->toBe('POSTED')
        ->and($body['Narration'])->toBe('Payout 2026-08-21')
        ->and($body['Date'])->toBe('2026-08-21')
        ->and($body['LineAmountTypes'])->toBe('NoTax')
        // Debits positive, credits negative, which is Xero's own convention.
        ->and((float) $body['JournalLines'][0]['LineAmount'])->toBe(100.0)
        ->and($body['JournalLines'][0]['AccountCode'])->toBe('200')
        ->and((float) $body['JournalLines'][1]['LineAmount'])->toBe(-100.0)
        ->and($fake->isDrained())->toBeTrue();
});

it('remembers the manual journal id against the local id it was posted for', function () {
    $map = new ArrayEntityMap;
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/manual-journal-created'));

    $journal = new JournalData(
        narration: 'Payout 2026-08-21',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [
            new JournalLine('200', Money::cents(10000)),
            new JournalLine('404', Money::cents(-10000)),
        ],
        localId: 'payout-7',
    );

    xero($fake, $map)->createEntity(EntityType::Journal, $journal, connection());

    expect($map->externalId(connection(), EntityType::Journal, 'payout-7'))->toBe('journal-1');
});

it('updates an entity by posting the whole body back to the resource id', function () {
    // Xero replaces rather than merges, so an update sends everything, and it
    // wants its own id inside the body as well as in the path. Sending only what
    // changed deletes the rest of the invoice.
    $map = new ArrayEntityMap;
    $map->remember(connection(), EntityType::Vendor, 'name:acme supply', 'contact-1');

    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/invoice-created'));

    $updated = xero($fake, $map)->updateEntity(EntityType::Bill, 'invoice-1', billFor(), connection());

    $body = $fake->requestBody(0)['Invoices'][0];

    expect($updated)->toBeTrue()
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]->getMethod())->toBe('POST')
        ->and((string) $fake->requests[0]->getUri())->toBe(XeroConnector::API_BASE.'/Invoices/invoice-1')
        ->and($body['InvoiceID'])->toBe('invoice-1')
        ->and($body['Type'])->toBe('ACCPAY')
        ->and($body['Contact'])->toBe(['ContactID' => 'contact-1'])
        ->and($body['Date'])->toBe('2026-08-21')
        ->and($body['LineItems'][0]['AccountCode'])->toBe('400')
        ->and((float) $body['LineItems'][0]['UnitAmount'])->toBe(25.0);
});

it('raises the provider error when an update is rejected', function () {
    $map = new ArrayEntityMap;
    $map->remember(connection(), EntityType::Vendor, 'name:acme supply', 'contact-1');

    $fake = fakeHttp();
    $fake->queue(400, providerResponse('xero/validation-error'));

    expect(fn () => xero($fake, $map)->updateEntity(EntityType::Bill, 'invoice-1', billFor(), connection()))
        ->toThrow(ValidationException::class);
});

it('revokes by finding the connection id for the tenant and deleting it', function () {
    // Xero revokes by deleting the connection, and a connection is keyed by its
    // own id rather than by the tenant id, so the list has to be walked first. A
    // bookkeeper may have authorised several organisations under one token, so
    // deleting the first entry would disconnect a company nobody asked about.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/connections'));
    $fake->queue(200, []);

    $second = new Connection(
        provider: Provider::Xero,
        tenantId: 'tenant-2',
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
        reference: 'org-99',
    );

    $revoked = xero($fake)->revoke($second);

    expect($revoked)->toBeTrue()
        ->and($fake->requests)->toHaveCount(2)
        ->and($fake->requests[0]->getMethod())->toBe('GET')
        ->and((string) $fake->requests[0]->getUri())->toBe(XeroConnector::CONNECTIONS_URL)
        ->and($fake->requests[1]->getMethod())->toBe('DELETE')
        ->and((string) $fake->requests[1]->getUri())
        ->toBe(XeroConnector::CONNECTIONS_URL.'/0f3e2d1c-8b7a-4d6e-9c5f-4a3b2c1d0e98')
        ->and($fake->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer access-token');
});

it('reports a failed revocation rather than throwing, so the local disconnect can proceed', function () {
    // The customer must be able to drop credentials even when Xero will not
    // cooperate, or they are stuck holding a connection they cannot get rid of.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/connections'));
    $fake->queue(400, ['Message' => 'Connection not found']);

    expect(xero($fake)->revoke(connection()))->toBeFalse();
});

it('uploads an attachment as raw octets with the filename in the path', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Attachments' => [['AttachmentID' => 'att-1']]]);

    $result = xero($fake)->attach(
        EntityType::Bill,
        'invoice-1',
        new Attachment('receipt', 'PDF-BYTES', 'application/pdf'),
        connection(),
    );

    $request = $fake->requests[0];

    expect($result->uploaded)->toBeTrue()
        ->and($result->filename)->toBe('receipt.pdf')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/octet-stream')
        ->and((string) $request->getUri())->toContain('/Invoices/invoice-1/Attachments/receipt.pdf')
        ->and($fake->bodies[0])->toBe('PDF-BYTES');
});

it('falls back to a smaller rendering when the first exceeds the 10MB ceiling', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Attachments' => [['AttachmentID' => 'att-1']]]);

    $result = xero($fake)->attach(
        EntityType::Bill,
        'invoice-1',
        AttachmentSet::of(
            new Attachment('email.pdf', str_repeat('x', XeroConnector::ATTACHMENT_LIMIT_BYTES + 1), 'application/pdf'),
            new Attachment('email.png', 'PNG-BYTES', 'image/png'),
        ),
        connection(),
    );

    expect($result->uploaded)->toBeTrue()
        ->and($result->filename)->toBe('email.png')
        ->and($fake->bodies[0])->toBe('PNG-BYTES');
});

it('reports rather than throws when every rendering is too large', function () {
    // The entity has already posted by the time this runs. Throwing would fail the
    // job, and the retry would post a second transaction.
    $fake = fakeHttp();

    $result = xero($fake)->attach(
        EntityType::Bill,
        'invoice-1',
        new Attachment('huge.pdf', str_repeat('x', XeroConnector::ATTACHMENT_LIMIT_BYTES + 1), 'application/pdf'),
        connection(),
    );

    expect($result->uploaded)->toBeFalse()
        ->and($result->reason)->toContain('over the 10.00 MB provider limit')
        ->and($fake->requests)->toBeEmpty();
});

it('reports rather than throws when Xero rejects the attachment', function () {
    $fake = fakeHttp();
    $fake->queue(400, ['Message' => 'The document is not valid']);

    $result = xero($fake)->attach(
        EntityType::Bill,
        'invoice-1',
        new Attachment('receipt.pdf', 'BYTES', 'application/pdf'),
        connection(),
    );

    expect($result->uploaded)->toBeFalse()
        ->and($result->reason)->toBe('The document is not valid');
});

it('surfaces Xero itemised validation errors', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(400, ['Elements' => [[
        'ValidationErrors' => [
            ['Message' => 'Account code 999 is not a valid code'],
            ['Message' => 'Date is required'],
        ],
    ]]]);

    expect(fn () => xero($fake)->createEntity(EntityType::Bill, billFor(), connection()))
        ->toThrow(ValidationException::class, 'Account code 999 is not a valid code; Date is required');
});

it('treats a rejected refresh token as a revocation, not a retryable failure', function () {
    $fake = fakeHttp();
    $fake->queue(400, ['error' => 'invalid_grant']);

    expect(fn () => xero($fake)->refresh(connection()))
        ->toThrow(ConnectionRevokedException::class);
});

it('treats a successful refresh with no access token in it as an authentication failure', function () {
    // Building a connection around an empty bearer token would surface later as a
    // misleading "revoked" on the next API call.
    $fake = fakeHttp();
    $fake->queue(200, ['token_type' => 'Bearer']);

    expect(fn () => xero($fake)->refresh(connection()))
        ->toThrow(AuthenticationException::class, 'no access token');
});

it('refreshes an expired token before attempting a best-effort revoke', function () {
    // With an expired token every revocation attempt 401s and the connection is
    // left dangling at Xero, working, while the host believes it disconnected.
    $fake = fakeHttp();
    $fake->queue(200, ['access_token' => 'fresh-token', 'expires_in' => 1800]);
    $fake->queue(200, [['id' => 'conn-1', 'tenantId' => 'tenant-1']]);
    $fake->queue(200, []);

    $ok = xero($fake)->revoke(connection(expires: '-1 hour'));

    expect($ok)->toBeTrue()
        ->and($fake->requests[0]->getUri()->getHost())->toBe('identity.xero.com')
        ->and($fake->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer fresh-token')
        ->and($fake->requests[2]->getMethod())->toBe('DELETE');
});

it('refreshes automatically before using an expired connection', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['access_token' => 'fresh-token', 'refresh_token' => 'fresh-refresh', 'expires_in' => 1800]);
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    xero($fake)->createEntity(EntityType::Bill, billFor(), connection(expires: '-1 hour'));

    expect($fake->requests[0]->getUri()->getHost())->toBe('identity.xero.com')
        ->and($fake->requests[2]->getHeaderLine('Authorization'))->toBe('Bearer fresh-token');
});

it('refuses a payload describing a different entity than the one requested', function () {
    // Catches the mistyped-argument bug before it becomes a bill posted as an
    // expense, which reconciles to the same total and is a nuisance to unpick.
    expect(fn () => xero(fakeHttp())->createEntity(EntityType::Expense, billFor(), connection()))
        ->toThrow(InvalidPayloadException::class, 'Cannot create a "expense": the payload describes a "bill"');
});

it('refuses a raw payload built for the other provider', function () {
    $raw = RawPayload::forQuickBooks(EntityType::Bill, ['VendorRef' => ['value' => '1']]);

    expect(fn () => xero(fakeHttp())->createEntity(EntityType::Bill, $raw, connection()))
        ->toThrow(InvalidPayloadException::class, 'built for QuickBooks Online and cannot be posted to Xero');
});

it('resolves a contact name inside a raw payload, so a straight port keeps working', function () {
    // AccountingPipe's existing XeroMapper emits Contact.Name rather than a
    // ContactID. A raw payload carrying that shape must still post.
    $fake = fakeHttp();
    $fake->queue(200, ['Contacts' => [['ContactID' => 'contact-1']]]);
    $fake->queue(200, ['Invoices' => [['InvoiceID' => 'invoice-1']]]);

    $raw = RawPayload::forXero(EntityType::Bill, [
        'Type' => 'ACCPAY',
        'Contact' => ['Name' => 'Acme Supply'],
        'LineItems' => [['Description' => 'Widgets', 'Quantity' => 1, 'UnitAmount' => 25.0]],
    ]);

    $id = xero($fake)->createEntity(EntityType::Bill, $raw, connection());

    expect($id)->toBe('invoice-1')
        ->and($fake->requestBody(1)['Invoices'][0]['Contact'])->toBe(['ContactID' => 'contact-1']);
});

it('reads bank accounts back by AccountID and coded accounts by Code', function () {
    // Xero line items address an account by code, but a spend-money bank account is
    // addressed by AccountID. Getting this backwards is a 400 that names neither.
    $fake = fakeHttp();
    $fake->queue(200, ['Accounts' => [
        ['AccountID' => 'bank-uuid', 'Name' => 'Business Checking', 'Code' => '090', 'Type' => 'BANK'],
    ]]);

    $banks = xero($fake)->bankAccounts(connection());

    expect($banks[0]->lineReference())->toBe('bank-uuid');

    $fake2 = fakeHttp();
    $fake2->queue(200, ['Accounts' => [
        ['AccountID' => 'exp-uuid', 'Name' => 'General Expenses', 'Code' => '400', 'Type' => 'EXPENSE'],
    ]]);

    expect(xero($fake2)->chartOfAccounts(connection())[0]->lineReference())->toBe('400');
});

it('builds an authorization url carrying offline_access', function () {
    // Without offline_access no refresh token is ever issued, and the connection
    // dies in 30 minutes no matter how good the refresh handling is.
    $url = xero(fakeHttp())->authorizationUrl('state-123');

    expect($url)->toStartWith(XeroConnector::AUTHORIZE_URL)
        ->and($url)->toContain('offline_access')
        ->and($url)->toContain('state=state-123')
        ->and($url)->toContain('accounting.attachments');
});

it('exchanges a code and discovers which organisations were authorised', function () {
    // Xero does not say which organisation the customer picked. A second call to
    // /connections does, and a bookkeeper signed in to several may have authorised
    // more than one, so the host must be able to ask which to use.
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/token'));
    $fake->queue(200, providerResponse('xero/connections'));

    $result = xero($fake)->exchangeCode('the-code');

    expect($result->tenantId)->toBe('tenant-1')
        ->and($result->tenants)->toHaveCount(2)
        ->and($result->needsTenantSelection())->toBeTrue()
        ->and($result->tokens->refreshToken)->not->toBeNull()
        ->and($fake->requests[0]->getHeaderLine('Authorization'))->toStartWith('Basic ')
        ->and($fake->requests[1]->getHeaderLine('Authorization'))->toStartWith('Bearer ');
});

it('reads the connected organisation back so a host can confirm the right company', function () {
    $fake = fakeHttp();
    $fake->queue(200, providerResponse('xero/organisation'));

    $info = xero($fake)->tenantInfo(connection());

    expect($info?->name)->toBe('Demo Company (US)')
        ->and($info?->legalName)->toBe('Demo Company Incorporated')
        ->and($info?->countryCode)->toBe('US')
        ->and($info?->currencyCode)->toBe('USD');
});

it('is the Xero provider', function () {
    expect(xero(fakeHttp())->provider())->toBe(Provider::Xero);
});
