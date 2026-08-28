# Implementation guide

Wiring `hei/accounting-connector` into a Laravel application, from install to the first posted
bill. Every step here is the host's side of the seam; the [README](../README.md) is the reference
for the package's side.

The examples use an `Organization` as the tenant model. Substitute your own — the package never
learns what a tenant is; it only ever sees the string you put in `Connection::$reference`.

## What you own vs. what the package owns

| You (the host) | The package |
|---|---|
| OAuth state/CSRF, sessions, routes, controllers | authorization URLs, code exchange, token refresh |
| when a document is ready to sync, and the queue it syncs on | translating it to the provider and posting it |
| rendering attachments (PDF, PNG) | picking the one that fits and uploading it |
| the sync log / audit trail (from events) | emitting the events |
| deciding what to do about a revoked connection | detecting revocation and telling you |

## Step 1 — Install

```bash
composer require hei/accounting-connector:^0.1
```

The package is on Packagist, so no repository entries or auth tokens are needed. On the 0.x
line treat minor versions as breaking — pin `^0.1` and read the CHANGELOG before `^0.2`.

## Step 2 — Register your provider apps

Each application registers **its own** apps. Intuit reviews per app, and the consent screen the
customer reads names the app requesting access.

- **Xero**: [developer.xero.com](https://developer.xero.com) → New app → Web app. Add every
  redirect URI you will use (local, staging, production) — Xero matches them exactly.
- **Intuit**: [developer.intuit.com](https://developer.intuit.com) → Create an app → QuickBooks
  Online and Payments. Same exact-match rule for redirect URIs. Create a **sandbox company**
  while you are there; you will want it before the first real post.

## Step 3 — Publish and configure

```bash
php artisan vendor:publish --tag=accounting-connector-config
php artisan vendor:publish --tag=accounting-connector-migrations
php artisan migrate
```

```env
XERO_CLIENT_ID=...
XERO_CLIENT_SECRET=...
XERO_REDIRECT_URI=https://app.example.com/integrations/xero/callback

QUICKBOOKS_CLIENT_ID=...
QUICKBOOKS_CLIENT_SECRET=...
QUICKBOOKS_REDIRECT_URI=https://app.example.com/integrations/quickbooks/callback
# While developing:
QUICKBOOKS_BASE_URL=https://sandbox-quickbooks.api.intuit.com
```

A provider with no client id is simply not registered; asking the manager for it throws a clear
message instead of 401ing against the vendor later. Consider adding the foreign key the migration
stub deliberately leaves out:

```php
$table->foreign('owner_id')->references('id')->on('organizations')->cascadeOnDelete();
```

## Step 4 — The connect flow

Two routes per provider: one that redirects out, one that handles the callback.

```php
// routes/web.php
Route::get('/integrations/{provider}/connect', [AccountingController::class, 'connect']);
Route::get('/integrations/{provider}/callback', [AccountingController::class, 'callback']);
```

```php
use Hei\AccountingConnector\ConnectorManager;
use Hei\AccountingConnector\Contracts\ConnectionRepository;
use Hei\AccountingConnector\Enums\Provider;

class AccountingController
{
    public function connect(Request $request, string $provider, ConnectorManager $connectors)
    {
        $connector = $connectors->for(Provider::from($provider));

        // The package does not manage state: it has no session. Generate, store, check.
        $state = Str::random(40);
        $request->session()->put('accounting_oauth_state', $state);

        return redirect()->away($connector->authorizationUrl($state));
    }

    public function callback(Request $request, string $provider, ConnectorManager $connectors)
    {
        abort_unless(
            hash_equals((string) $request->session()->pull('accounting_oauth_state'), (string) $request->query('state')),
            403,
        );

        $connector = $connectors->for(Provider::from($provider));

        // Pass the whole query through: Intuit delivers the company as ?realmId=...
        // on the callback, not in the token response. Harmless for Xero.
        $result = $connector->exchangeCode((string) $request->query('code'), $request->query());

        // A bookkeeper signed in to six Xero organisations may have authorised more
        // than one. Skipping this is how money lands in a stranger's ledger.
        if ($result->needsTenantSelection()) {
            // Stash $result (session/cache), show $result->tenants, then build the
            // connection with toConnection($chosenTenantId, ...) on the next request.
        }

        $connection = $result->toConnection(
            settings: [],
            reference: (string) $request->user()->organization_id,
        );

        app(ConnectionRepository::class)->save($connection);

        // Fill the lookup store now, not on the first settings page load — and this
        // is also what evicts stale lists if the customer reconnected to a
        // DIFFERENT organisation than before.
        $connector->refreshLookups($connection);

        // Show the customer which company they connected.
        $info = $connector->tenantInfo($connection);

        return redirect()->route('settings.integrations')
            ->with('status', "Connected to {$info?->name}.");
    }
}
```

## Step 5 — The disconnect flow

Order matters: revoke at the provider first (best effort), then locally.

```php
public function disconnect(Request $request, string $provider)
{
    $providerEnum = Provider::from($provider);
    $owner = (string) $request->user()->organization_id;

    $repo = app(ConnectionRepository::class);
    $connection = $repo->find($owner, $providerEnum);

    if ($connection !== null) {
        // Returns false rather than throwing on failure: the local disconnect has
        // to happen either way, or the customer is stuck holding credentials.
        app(ConnectorManager::class)->for($providerEnum)->revoke($connection);

        // flush() is on the shipped implementation, not the LookupStore contract.
        $lookups = app(LookupStore::class);

        if ($lookups instanceof DatabaseLookupStore) {
            $lookups->flush($connection);
        }
    }

    $repo->forget($owner, $providerEnum);
    // Or, to keep the row and show "reconnect Xero" instead:
    // $repo->markRevoked($owner, $providerEnum, 'Disconnected by ' . $request->user()->email);
}
```

## Step 6 — The settings page

Three dropdowns from one connection, no provider branching:

```php
$connector = app(ConnectorManager::class)->forConnection($connection);

$accounts = $connector->chartOfAccounts($connection);       // one call, cached
$expense  = Account::only($accounts, AccountClass::Expense);
$banks    = $connector->bankAccounts($connection);          // filter, no extra call
$taxes    = $connector->taxCodes($connection);
$tracking = $connector->trackingCategories($connection);    // [] on QuickBooks — render nothing
```

Store what the customer picks in the connection's settings — the connectors read
`bank_account` themselves when a payload does not carry one:

```php
$repo->save($connection->withSettings([
    'bank_account' => $request->input('bank_account'),      // Account::$id (Xero bank accounts use the id, not the code)
    'expense_account' => $request->input('expense_account'), // Account::lineReference()
    'tax_code' => $request->input('tax_code'),               // TaxCode::$reference
]));
```

Use `Account::lineReference()` for anything that will land on a line item; it returns the code
for Xero coded accounts and the opaque id for QuickBooks, so the value is right for whichever
provider the connection points at. A "refresh accounts" button calls
`$connector->refreshLookups($connection)`.

## Step 7 — Posting documents from a queue job

```php
use Hei\AccountingConnector\Data\{BillData, LineItem, Money, AttachmentSet, Attachment};
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Exceptions\{ConnectionRevokedException, RateLimitException, ServerException, ValidationException};
use Hei\AccountingConnector\Support\IdempotencyKey;

class SyncBillToAccounting implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Document $document) {}

    // One sync job per connection at a time. This is ALSO your protection against
    // the concurrent-refresh race described in the README: both providers rotate
    // refresh tokens, and two simultaneous refreshes can kill a healthy connection.
    public function middleware(): array
    {
        return [(new WithoutOverlapping('accounting:'.$this->document->organization_id))->expireAfter(120)];
    }

    public function handle(ConnectorManager $connectors, ConnectionRepository $repo): void
    {
        $connection = $repo->find((string) $this->document->organization_id, $this->document->accountingProvider());

        if ($connection === null) {
            return; // not connected; nothing to do
        }

        $connector = $connectors->forConnection($connection);

        $payload = new BillData(
            vendor: $this->document->vendor_name,
            date: $this->document->issued_on,
            lines: $this->document->lines->map(fn ($line) => new LineItem(
                description: $line->description,
                unitAmount: Money::cents($line->unit_cents),
                quantity: (float) $line->quantity,
                accountCode: $line->account_reference ?? $connection->setting('expense_account'),
                taxCode: $connection->setting('tax_code'),
            ))->all(),
            documentNumber: $this->document->vendor_invoice_number,
            localId: (string) $this->document->id,
        );

        try {
            $externalId = $connector->createEntity(
                EntityType::Bill,
                $payload,
                $connection,
                // Deterministic: the same document retried tomorrow produces the same
                // key, so a retry after an ambiguous failure cannot double-post.
                IdempotencyKey::for(EntityType::Bill, (string) $this->document->id),
            );
        } catch (ValidationException $e) {
            // Not retryable as-is. Surface $e->errors to the customer; the provider's
            // own wording is usually the only thing that says what to fix.
            $this->document->markSyncRejected($e->errors);

            return;
        } catch (ConnectionRevokedException) {
            // Stop dispatching for this connection; a human must reconnect.
            return;
        } catch (RateLimitException|ServerException $e) {
            // Retryable later. The idempotency key makes the retry safe.
            $this->release($e instanceof RateLimitException ? ($e->retryAfter ?? 120) : 120);

            return;
        }

        if ($externalId === null) {
            $this->release(300); // accepted but no id came back; retry replays via the key

            return;
        }

        // Record the external id NOW (or in an EntityCreated listener). If the
        // attach below fails and the job retries, this is what stops a second bill.
        $this->document->update(['accounting_external_id' => $externalId]);

        // Attachments never throw. Offer fallbacks: Xero caps at 10 MB and a
        // rendered PDF often exceeds it where the PNG does not.
        $result = $connector->attach(
            EntityType::Bill,
            $externalId,
            AttachmentSet::of(
                new Attachment($this->document->filename('pdf'), $this->document->renderPdf(), 'application/pdf'),
                new Attachment($this->document->filename('png'), $this->document->renderPng(), 'image/png'),
            ),
            $connection,
        );

        if (! $result->uploaded) {
            logger()->warning('Bill posted but receipt not attached', [
                'document' => $this->document->id,
                'reason' => $result->reason,
            ]);
        }
    }
}
```

Payments are the one two-step entity: create the invoice first, read its external id back (from
your own column or `EntityMap::externalId()`), then post the `PaymentData` with that id — and a
customer, which QuickBooks requires.

Two QuickBooks behaviours worth knowing before the first invoice: the connector posts each
invoice line through a Service **item** wired to the line's income account, creating one named
after the account the first time — the customer will see those in their Products & Services
list, which is normal for QBO integrations. And a **US** QuickBooks company rejects
`LineAmountType::Inclusive` outright ("Inclusive Tax Type is not allowed"), surfaced as a
`ValidationException` — hold inclusive-tax documents for non-US companies only.

## Step 8 — Listen to the events

The events are your sync log. Register listeners as usual:

```php
Event::listen(EntityCreated::class, RecordSyncSuccess::class);
Event::listen(EntityCreateFailed::class, RecordSyncFailure::class);   // check $event->retryable
Event::listen(AttachmentUploaded::class, RecordAttachmentOutcome::class);
Event::listen(ConnectionRevoked::class, HandleRevokedConnection::class);
```

```php
class HandleRevokedConnection
{
    public function handle(ConnectionRevoked $event): void
    {
        app(ConnectionRepository::class)->markRevoked(
            (string) $event->connection->reference,
            $event->provider(),
            $event->reason,
        );

        // Tell somebody. Sync jobs for this connection achieve nothing until a
        // human reconnects, and the customer thinks they are synced.
    }
}
```

Every event's `context()` is safe to serialise straight into a log table — no tokens, ever.
Note the gap documented in the README: a create that dies in the pre-create token refresh emits
`ConnectionRevoked` but not `EntityCreateFailed`, so a sync log keyed on the latter alone will
miss those documents.

## Step 9 — Queue discipline

- **One sync job per connection at a time** (`WithoutOverlapping` keyed on the tenant, as in
  Step 7). This respects Xero's 5-in-flight ceiling and prevents the concurrent-refresh race.
- **Modest total concurrency.** Xero allows 60 calls a minute per tenant, shared with every other
  app the customer connected. The package's retries handle bursts; they do not make twenty
  workers a good idea.
- **Back off on `RateLimitException`** using its `retryAfter` rather than a fixed delay.

## Step 10 — Test your integration

Bind the fake; the whole path above becomes testable with no HTTP:

```php
$fake = new FakeConnector(Provider::Xero);
app(ConnectorManager::class)->set(Provider::Xero, $fake);

SyncBillToAccounting::dispatchSync($document);

expect($fake->createdOf(EntityType::Bill))->toHaveCount(1)
    ->and($fake->created[0]['idempotency_key'])->toBe('sync-bill-'.$document->id)
    ->and($document->refresh()->accounting_external_id)->not->toBeNull();
```

Cover the branches the fake can simulate:

- `failNextCreate(new ValidationException(...))` — the rejection path in Step 7
- `failNextCreate(new ConnectionRevokedException(...))` — the stop-dispatching path
- `nextCreateReturnsNoId()` — the accepted-but-no-id release path
- `nextAttachment(AttachmentResult::failed('too large'))` — posted-but-not-attached logging
- `failLookups(new ServerException(...))` — the settings page during a provider outage

The fake also refuses what the real connectors refuse (wrong payload type, unsupported entity,
raw payload for the other provider), so a mistyped call fails in your test suite rather than in
production.

## Checklist

- [ ] Provider apps registered, redirect URIs exact, QBO sandbox created
- [ ] Config + migrations published, `owner_id` foreign key added
- [ ] Connect flow with state check, tenant selection, `refreshLookups()` after save
- [ ] Disconnect flow: revoke → flush lookups → forget/markRevoked
- [ ] Settings page storing `bank_account` (and defaults) in connection settings
- [ ] Sync jobs: idempotency keys on every create, `WithoutOverlapping` per connection
- [ ] External ids recorded the moment `EntityCreated` fires
- [ ] `ConnectionRevoked` listener that marks the row and tells somebody
- [ ] Host test suite running against `FakeConnector`
