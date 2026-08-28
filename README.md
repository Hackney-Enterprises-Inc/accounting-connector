# Accounting Connector

[![tests](https://github.com/Hackney-Enterprises-Inc/accounting-connector/actions/workflows/tests.yml/badge.svg)](https://github.com/Hackney-Enterprises-Inc/accounting-connector/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hei/accounting-connector.svg)](https://packagist.org/packages/hei/accounting-connector)
[![License](https://img.shields.io/packagist/l/hei/accounting-connector.svg)](LICENSE)

One interface for posting to Xero and QuickBooks Online. OAuth2, canonical AR and AP entities,
entity mapping, idempotency keys, and attachment handling with the provider quirks already solved.

Requires PHP 8.3+. Laravel 12 or 13 is optional; the core is framework-free.

**New to the package? Start with the [implementation guide](docs/implementation-guide.md)** — a
step-by-step walkthrough of wiring it into a Laravel application, from install to the first
posted bill. This README is the reference.

## Installing

```bash
composer require hei/accounting-connector
```

That's it — the package is on [Packagist](https://packagist.org/packages/hei/accounting-connector),
so no repository entries or auth tokens are needed anywhere. On the 0.x line, treat minor
versions as breaking: pin `^0.1` and read the CHANGELOG before moving to `^0.2`.

### Working on the package and an application together

Pushing to `main` and running `composer update hei/accounting-connector` for every edit is
too slow to iterate against. Two options:

**Point composer at the checkout while you work.** Add a path repository (custom repositories
are consulted before Packagist) and composer symlinks the working tree into `vendor/`:

```json
{
    "type": "path",
    "url": "../../packages/accounting-connector",
    "options": { "symlink": true }
}
```

Remove it before committing. Left in, it fails every build that does not have the package
checked out at that relative path, which is CI and every server.

**Or push, then update.** Slower per iteration, but it is what CI and the servers actually
do, so it catches a broken tag or a missing file before a deploy does.

Note that the lock file records which source supplied the package. A lock written against the
path repository points at a directory that does not exist anywhere else, so re-run
`composer update hei/accounting-connector` after removing the path entry, and check that the
resulting lock entry names the Packagist dist again.

## What this is

A provider layer, not an accounting system. It knows how to talk to Xero and Intuit and nothing
else. It has no database, no queue, no models, no opinion about what a tenant is, and no idea
whether a given document is ready to sync.

It exists because two products needed the same provider layer and neither wanted to own it twice.

Why not an off-the-shelf package? Because there isn't one. The published options are
single-provider SDKs (`calcinai/xero-php`, `jarvus/laravel-quickbooks`) with no shared interface,
or full double-entry applications (`centrex/laravel-accounting`) that are far more than a
connector. The multi-provider abstraction only exists as hosted SaaS, and the one self-hostable
option leaves data normalisation to you, which is the entire hard part.

## The seam

```php
$externalId = $connector->createEntity(
    EntityType::Bill,
    new BillData(
        vendor: 'Acme Supply',
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem(
            description: 'Widgets',
            unitAmount: Money::cents(2500),
            quantity: 3,
            accountCode: '400',
        )],
        localId: 'doc-42',
    ),
    $connection,
    idempotencyKey: IdempotencyKey::for(EntityType::Bill, 'doc-42'),
);
```

The same call posts to QuickBooks by handing it a QuickBooks connection. The connector translates.

## The OAuth flow, end to end

The package builds URLs and exchanges codes; sessions, CSRF state and storage are yours.

```php
// 1. Send the customer to the provider. $state is a CSRF nonce you generate,
//    store (session, cache), and check on the way back.
return redirect($connector->authorizationUrl($state));

// 2. In the callback, after checking $state, trade the code for tokens.
//    Intuit puts the company (realm) id in the callback query; pass it through.
$result = $connector->exchangeCode(
    $request->query('code'),
    $request->query(),          // carries realmId for QuickBooks; harmless for Xero
);

// 3. A Xero user signed in to several organisations may have authorised more
//    than one. Ask which to use before assuming the first.
if ($result->needsTenantSelection()) {
    // show $result->tenants, then call toConnection($chosenTenantId, ...)
}

// 4. Turn the result into a Connection and store it. reference is YOUR tenant id;
//    it is what the shipped repository stores the row against.
$connection = $result->toConnection(settings: [], reference: (string) $organization->id);
app(ConnectionRepository::class)->save($connection);

// 5. Confirm to the customer which company they connected. A bookkeeper who
//    picked the wrong one of six finds out now, not when the first transaction
//    lands in a stranger's ledger.
$info = $connector->tenantInfo($connection);
```

**Disconnecting** is three steps, in this order: `$connector->revoke($connection)` (best effort —
it returns `false` rather than throwing, because the local disconnect must happen either way),
then `ConnectionRepository::forget()` (or `markRevoked()` to keep the row for a "reconnect"
banner), then drop cached lookups (`DatabaseLookupStore::flush()`).

**Reconnecting** to a possibly different organisation: call `refreshLookups()` after saving the
new connection. Stored lookup lists are keyed by your tenant, deliberately, so they survive a
token reconnect — which also means a reconnect that lands on a *different* provider organisation
serves the old organisation's chart of accounts until refreshed.

## Core ideas

### Connection replaces your tenant model

`Connection` is a plain readonly object carrying provider, tenant id, tokens, expiry and
per-connection settings. It is deliberately not company-shaped, so the package works for an app
whose tenant is an `Organization` and one whose tenant is a `Company` without knowing the difference.

Tokens arrive already decrypted. **The package never encrypts and never persists.** Your app owns
the key; the shipped Laravel repository encrypts with the application key, and a host that stores
tokens itself uses its own `encrypted` cast.

`Connection` is immutable, so a refresh returns a new instance. Use the returned one, because the
one you passed in still holds the old access token.

### Token refresh, and why concurrency matters

Both providers expire access tokens in about half an hour. Every entity call refreshes an
expired connection automatically, and the refreshed tokens are handed to the bound
`ConnectionStore` **before** the new access token is used for anything — because Intuit rotates
the refresh token on every refresh, and a rotated token that is never persisted kills the
connection days later with no obvious cause.

What the package cannot do is serialize your workers. Two queue jobs that hit the same expired
connection at the same moment will both refresh; both providers rotate refresh tokens, so the
loser can get `invalid_grant` back — which is indistinguishable from a real revocation and is
reported as one. Xero softens the race (the previous refresh token stays valid for about 30
minutes after rotation) and Intuit rotates the token value roughly daily rather than per call,
so in practice this is rare — but the fix is on your side of the seam:

- keep sync-queue concurrency per connection at 1 (a keyed queue, or a
  `WithoutOverlapping` / cache-lock middleware keyed on the connection), or
- take a short per-connection lock around any call that may refresh.

Treat the first `ConnectionRevokedException` on a healthy connection with suspicion if you run
concurrent workers without a lock.

### Money is integer cents

`Money::cents(12500)`. Both providers want decimals on the wire; the conversion happens once, at the
boundary. `Money::cents(3333)->times(3)` is exactly `99.99`, not `99.98999999999999`.

### Idempotency is not optional

A timeout after the provider committed but before you saw the response is indistinguishable from a
failure. Without a key, the retry posts a second transaction into a customer's ledger.

The two providers differ in a way that bites: Xero takes a 128-character `Idempotency-Key` header,
Intuit takes a 50-character `requestid` query parameter, and **a duplicate `requestid` does not
error, it silently replays the original response**. So `IdempotencyKey::truncate()` hashes rather
than cuts, because two keys differing only past character 50 would otherwise dedupe two distinct
posts and one document would vanish without a trace.

Pass `null` only when a second entity is genuinely wanted, such as a user-initiated resync.

### Attachments never throw

By the time an attachment uploads, the transaction already exists in the customer's ledger.
Throwing would fail the job, and the retry would post a second transaction. So `attach()` returns
an `AttachmentResult` and never throws. It catches `Throwable`, not just its own exceptions.

Xero caps attachments at 10 MB and a rendered email PDF regularly exceeds it. Offer fallbacks:

```php
$result = $connector->attach(
    EntityType::Bill,
    $invoiceId,
    AttachmentSet::of($pdf, $png),   // first one that fits wins
    $connection,
);

if (! $result->uploaded) {
    logger()->warning('Receipt not attached', ['reason' => $result->reason]);
}
```

Rendering is your job. The package picks, it does not convert.

### Lookups are cached, stored, and survive an outage

Chart of accounts, tax codes and tracking categories resolve through three layers: PSR-16 cache,
then a durable `LookupStore`, then the provider. Each earns its place.

The cache alone is not enough, and AccountingPipe already learned why. A deploy that flushes the
cache otherwise sends every organization back to Xero on the next settings page load, against a
60-calls-per-minute ceiling the customer shares with every other app they have connected. And when
the provider is unreachable, a stored list is served with a warning rather than an empty one,
because an empty account dropdown reads to a customer as "my chart of accounts is gone". With
nothing ever stored, the error propagates instead of pretending the list is empty.

One call, three dropdowns: `chartOfAccounts()` fetches every active account and each `Account`
carries a portable `AccountClass`, so `bankAccounts()` is a filter rather than a second API call.

```php
$accounts = $connector->chartOfAccounts($connection);

$expense = Account::only($accounts, AccountClass::Expense);   // Xero EXPENSE/DIRECTCOSTS/OVERHEADS
$income  = Account::only($accounts, AccountClass::Revenue);   // QBO Income/Other Income
$banks   = $connector->bankAccounts($connection);             // no extra request
```

`trackingCategories()` is Xero-only and returns an empty list on QuickBooks rather than throwing,
so one dropdown renders for both providers without branching. Archived categories and archived
options are filtered out, because Xero keeps returning them and offering one produces a post Xero
then rejects.

`refreshLookups()` backs a "refresh accounts" button — and belongs in your connect flow, per the
reconnect note above.

QuickBooks lookups read at most 1,000 rows (Intuit's page ceiling); a company with more active
accounts than that would need paging the package deliberately does not do.

QuickBooks keeps a fourth lookup the others do not need: the **item list**. QBO sales lines are
item-based — the live API ignores a direct income-account reference — so when an invoice line
addresses an income account, the connector resolves it to a Service item wired to that account,
creating one named after the account the first time. Those items appear in the customer's
Products & Services list, which is expected; it is how every QBO integration posts to a chosen
income account. A line with no `accountCode` falls through to the company's default item.

### The entity map is a contract, not a table

`EntityMap` maps your local id to the provider's external id, per connection and entity type.
Three implementations ship: `ArrayEntityMap` (tests), `NullEntityMap` (default, remembers nothing),
and `Laravel\DatabaseEntityMap` over a publishable table.

Every implementation **must** scope lookups to the tenant, not just the provider. Two organizations
will both hold a local id of `1`, and crossing them posts a customer's money into a stranger's ledger.

It also caches contact resolution, which removes a round trip per contact per post.

### Revocation is a signal, not an error

`ConnectionRevokedException` and the `ConnectionRevoked` event mean the customer disconnected you or
the refresh token lapsed. Stop dispatching jobs for that connection and tell somebody. Retrying
achieves nothing. (But see the concurrency note above before treating the first one as gospel.)

## Events

The package emits PSR-14 events and persists nothing. Every event extends `SyncEvent` and has a
`context()` array that is safe to write straight to a database column — no tokens, ever.

| Event | When | The field that matters |
|---|---|---|
| `EntityCreated` | provider confirmed the id, before any attachment | `externalId` — record it immediately |
| `EntityCreateFailed` | a create was refused or returned no id | `retryable` — validation needs a human, a rate limit just needs the job re-run |
| `AttachmentUploaded` | an attachment attempt finished, either way | `result->uploaded` |
| `TokensRefreshed` | tokens renewed and already persisted | observability only — do not persist from here |
| `ConnectionRevoked` | the grant is dead, a human must reconnect | `reason` |

One deliberate gap: a create that dies in the pre-create token refresh raises (and, on a dead
grant, emits `ConnectionRevoked`) **without** an `EntityCreateFailed`, because no create was ever
attempted. A sync log keyed on `EntityCreateFailed` alone will not show those documents; listen
for `ConnectionRevoked` as well.

## Exceptions

Everything the package throws extends `AccountingConnectorException`, which carries the
`Provider` and the provider's own message.

| Exception | Meaning | Retry? |
|---|---|---|
| `InvalidPayloadException` | refused before any HTTP call; nothing was posted | fix the payload |
| `ValidationException` | provider rejected it (400/422); `errors` itemises | fix the payload |
| `AuthenticationException` | credentials refused (401/403) and a refresh will not fix it | no |
| `ConnectionRevokedException` | the grant is dead; carries the `Connection` | reconnect only |
| `NotFoundException` | no such record (404) | no |
| `RateLimitException` | 429 and the retry budget is spent; carries `retryAfter` | later |
| `ServerException` | provider 5xx or transport failure, retries exhausted | later — always with an idempotency key |
| `UnsupportedEntityTypeException` | this provider cannot represent the type | check `supports()` first |

## Rate limits

Xero, all per tenant: **60 calls a minute**, **5,000 a day** once your app is certified (1,000
before), and **no more than 5 requests in flight**. The per-minute ceiling is shared with every
other app your customer has connected, so a busy organization can rate-limit you through no fault of
your own.

The bundled `HttpClient` honours `Retry-After` exactly, backs off 5xx with full jitter, never retries
a 4xx, and surfaces `X-MinLimit-Remaining` / `X-DayLimit-Remaining`. It does not protect you from
running twenty sync workers at once, so keep queue concurrency modest.

## No vendored SDKs

Both connectors speak plain JSON REST over PSR-18. Xero's official SDKs are a convenience, not a
requirement, and certification cares about behaviour rather than your client library. Going direct
keeps `xeroapi/xero-php-oauth2`'s transitive `firebase/php-jwt` constraint out of your application,
makes both adapters symmetric and identically testable, and adds 429 handling that neither vendor
SDK provides.

The wire details this reproduces: `xero-tenant-id` on every call, `Accept: application/json`
(the Accounting API answers in XML without it), `Idempotency-Key` on creates, attachments as raw
octets with the filename in the URL path, Xero's `/Date(1518685950940+0000)/` response format
(handled by `XeroDate::parse()`), Intuit's `requestid`/`SyncToken`/minor-version conventions, and
`GlobalTaxCalculation` on QuickBooks documents.

## Laravel

Auto-discovered. Publish what you need:

```
php artisan vendor:publish --tag=accounting-connector-config
php artisan vendor:publish --tag=accounting-connector-migrations
```

Republishing the migrations is safe: an already-published migration keeps its filename and is
overwritten in place rather than gaining a second timestamped copy.

Three tables, one purpose each. Nothing to bind:

```php
$connection = app(ConnectionRepository::class)->find($company->id, Provider::Xero);
$connector  = AccountingConnectors::forConnection($connection);
```

Tokens are encrypted with your application key on the way in, and the same object satisfies
`ConnectionStore`, so rotated QuickBooks refresh tokens persist with no glue from you. That
mattered: Intuit rotates its refresh token on every single refresh, and one missed write kills the
connection days later with no obvious cause.

To keep connections where you already have them, set `accounting-connector.connections.enabled` to
false and bind your own `ConnectionStore`. The fallback warns loudly every time it discards a
refresh, for the reason above.

Sync events go through Laravel's dispatcher, so `Event::listen()` and listener classes work as
usual. The provider deliberately does **not** bind `Psr\EventDispatcher\EventDispatcherInterface`
app-wide; if you want the bridge itself, resolve
`Hei\AccountingConnector\Laravel\LaravelEventDispatcher`.

Each application registers **its own** Xero app and **its own** Intuit app. Intuit reviews per app
and the consent screen names the app, which is exactly why this is a package and not a service.

### Config reference

All keys live in `config/accounting-connector.php` after publishing.

| Key | Env | Default | What it does |
|---|---|---|---|
| `xero.client_id` / `client_secret` / `redirect_uri` | `XERO_*` | — | OAuth app credentials; provider unregistered while empty |
| `quickbooks.client_id` / `client_secret` / `redirect_uri` | `QUICKBOOKS_*` | — | same for Intuit |
| `quickbooks.base_url` | `QUICKBOOKS_BASE_URL` | production | point at `https://sandbox-quickbooks.api.intuit.com` for a sandbox company |
| `quickbooks.minor_version` | `QUICKBOOKS_MINOR_VERSION` | `75` | Intuit API minor version; pin deliberately, re-test when moved |
| `http.max_retries` | `ACCOUNTING_CONNECTOR_MAX_RETRIES` | `3` | retries for 429/5xx/transport only |
| `cache.enabled` / `store` / `ttl` | `ACCOUNTING_CONNECTOR_CACHE*` | on / default store / 3600 | the PSR-16 layer in front of lookups |
| `connections.enabled` / `connection` / `table` | `ACCOUNTING_CONNECTOR_CONNECTIONS`, `ACCOUNTING_CONNECTOR_DB_CONNECTION` | on | the shipped connection repository + store |
| `lookups.enabled` / `connection` / `table` | `ACCOUNTING_CONNECTOR_LOOKUP_STORE` | on | the durable lookup store |
| `entity_map.enabled` / `connection` / `table` | `ACCOUNTING_CONNECTOR_ENTITY_MAP` | on | the database entity map |
| `events.enabled` | `ACCOUNTING_CONNECTOR_EVENTS` | on | silence sync events entirely |

## Schema

Three tables, published rather than migrated for you, because each application owns its own schema:

| Table | Rows | Holds |
|---|---|---|
| `accounting_connections` | one per tenant per provider | tenant id, encrypted tokens, expiry, settings, revocation status |
| `accounting_connection_lookups` | 3-4 per connection | cached chart of accounts, tax codes, tracking categories |
| `accounting_entity_map` | one per posted entity, grows forever | local id to external id, both directions |

They are deliberately separate. Merging them costs you what makes each one safe:

- The entity map's guarantee is `unique(provider, tenant_id, entity_type, local_id)`. That is what
  makes `remember()` an upsert instead of a duplicate-key crash inside a queue job. A shared table
  would need a type discriminator and nullable columns, and the database would enforce nothing.
- Every sync job reads a connection. A chart of accounts can be a hundred kilobytes of JSON, and no
  job should drag that just to get an access token.
- One row per lookup key means each refresh is an independent upsert. A single JSON column holding
  all three would make every refresh a read-modify-write, so two concurrent refreshes would
  silently clobber one another.

**`accounting_connections.provider` is not constrained to this package's providers.** Keep
connections for integrations the package has no connector for in the same table and read them with
your own service; rows the package does not recognise are stepped over, not treated as errors.
That is how a third or fourth integration costs a row rather than four more columns on your tenant
model.

`owner_id` is your own tenant identifier as a string, and it is what lands in
`Connection::$reference`. The stub declares no foreign key, because the package cannot know whether
you call your tenant an Organization or a Company. Add it yourself.

## Testing

Bind `FakeConnector` and the whole sync path is testable with no HTTP faking:

```php
$fake = new FakeConnector(Provider::Xero);
app(ConnectorManager::class)->set(Provider::Xero, $fake);

// exercise your app

expect($fake->createdOf(EntityType::Bill))->toHaveCount(1);
```

It can also fail on demand: `failNextCreate()`, `nextCreateReturnsNoId()`,
`nextAttachment(AttachmentResult::failed(...))`, `failLookups()`, `doesNotSupport(...)`. And it
makes the same refusals the real connectors do — an unsupported entity type, a payload describing
a different entity, a raw payload built for another provider — so a host test that passes the
wrong payload fails in the test rather than in production.

For testing the connectors themselves, `FakeHttpClient` is a PSR-18 client that answers from a
queue, and `tests/Fixtures/{xero,quickbooks}/` holds doc-derived JSON responses for both
providers.

## Provider differences that are not portable

Worth knowing before you assume symmetry.

| | Xero | QuickBooks Online |
|---|---|---|
| Line account | short code (`'400'`) | opaque per-company id (`'63'`) |
| Draft status | honoured | **ignored**, anything posted is posted for real |
| Line quantity | native | folded into the amount on expense lines |
| Tax mode | `LineAmountTypes` on the document | `GlobalTaxCalculation` — US companies drop `TaxExcluded` and **reject `Inclusive` with a validation error** |
| Invoice line account | addressed directly by code | via a Service **item** the connector find-or-creates per income account (`ItemAccountRef` is ignored by the live API); items appear in the customer's Products & Services list |
| Tracking categories | native | **dropped** on write, empty list on read |
| Payment | invoice alone | requires a customer too |
| Expense payment method | n/a | `PaymentType`: Cash, Check or CreditCard only |
| Update | replace | replace, and needs the current `SyncToken` |
| Attachment | 10 MB, raw octets | 20 MB here, multipart with two literally-named parts |
| Response dates | `/Date(...)/` | plain ISO |
| `tenantInfo()` currency | populated | **null** — CompanyInfo carries no currency element |

Read `Account::lineReference()` rather than assembling an account reference yourself; it returns the
right form for whichever provider the connection points at.

## The escape hatch

When the canonical shape does not model something, or you are porting call sites one at a time:

```php
RawPayload::forXero(EntityType::Bill, ['Type' => 'ACCPAY', 'Contact' => ['Name' => 'Acme'], ...]);
```

Contact resolution still runs, so a raw payload carrying `Contact.Name` (Xero) or `VendorName`
(QuickBooks) works, which is exactly the shape existing AccountingPipe mappers emit. The payload
declares its provider and is refused if handed to the other one.

## Quality gate

```
composer test      # pest
composer analyse   # phpstan level 8
composer format    # pint
```

## Licence

MIT.
