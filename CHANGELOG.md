# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2026-09-23

### Added

- `ListsContacts`, an optional contract (ask with `instanceof`, like `ReadsBankTransactions`):
  `contacts(Connection $connection, ?DateTimeInterface $modifiedSince = null): iterable<Contact>`.
  The Xero connector implements it: `GET Contacts` paged 100 at a time with
  `includeArchived=true`, ordered by `ContactID`, with `If-Modified-Since` when
  `$modifiedSince` is given (a 304 yields nothing). Every contact is listed, customers and
  non-suppliers included: Xero sets `IsSupplier` only from bills, so a vendor paid by card
  or bank transfer is never flagged. Pages are fetched as the caller iterates. QuickBooks
  does not implement it. Listings are not snapshots; hosts should periodically
  reconcile in full, including supplier/customer flag-only changes that Xero excludes
  from incremental results.
- `Contact::$mergedToContactId` and `Contact::$updatedAt` (from Xero's `MergedToContactID`
  and `UpdatedDateUTC`), carried by `toArray()` and the new `Contact::fromArray()`; a
  payload stored without them hydrates with both null. `status` documents Xero's
  ACTIVE / ARCHIVED / GDPRREQUEST.
- `FakeConnector` implements `ListsContacts`: `withContacts()` seeds the listing (keyed by
  contact id, re-seeding replaces), `contacts()` honours `$modifiedSince` against
  `updatedAt`, records each call in `$contactListings`, and fails with `failLookups()`.

## [0.2.1] - 2026-09-23

### Added

- `Account::$description` (nullable string), mapped from Xero's account `Description` (an
  empty one reads as null) and carried through `toArray()` / `fromArray()` as `description`.
  A stored payload without the field hydrates with `description` null.

### Changed

- `AccountingConnector::LOOKUP_CHART_OF_ACCOUNTS` is `chart_of_accounts_v3`, so chart rows
  stored before `description` existed are bypassed and fetched afresh once rather than read
  as accounts with no description.
- `DatabaseLookupStore` scopes every row to the connection's tenant: `lookup_key` is stored
  as `<key>@<first 16 hex of sha256(tenant id)>`, the hash `Connection::cacheKey()` uses. An
  owner that reconnects to a different Xero organization or QuickBooks company is never
  served the previous company's lists. Rows written by 0.2.0 carry no tenant and are treated
  as a miss; no migration is needed, and `flush()` still clears every tenant of an owner.
  `get()`, `put()` and `syncedAt()` keep their signatures.

## [0.2.0] - 2026-09-20

### Changed

- Every bank transaction write that echoes line amounts (the recode, the delete) carries
  `unitdp=4` like the reads, so a four-place `UnitAmount` is not rounded to cents by Xero on the
  way in and the row it answers with compares at the same precision (invariant 15).
- The tax guard before a recode is exact: a stored line tax that differs from the rate's
  recomputation by any amount, a cent included, is refused with `tax_override_would_be_lost`
  before anything is sent. The cent of slack let a write "correct" the tax and move the total.
- `RecodeInvariants::movedMoney()` compares each line's tax amount and exact unit price as well
  as its amount and quantity, so taxes moving between lines under unchanged header totals, or a
  unit price the provider re-rounded, are reported as moved money.
- A recode retried with the same expectation after a lost response no longer fails its
  precondition: when the connector's own read already shows exactly the state the change would
  have produced from the expected one (targeted lines coded as asked, every other line as
  expected, the provider's stamp not gone backwards), nothing is sent and the read is returned as
  `RecodeResult` with `recovered: true`; its `before` is the expectation laid over that read.
  `BankTransactionChange::isSatisfiedBy()` / `codesAfter()`, `BankTransactionData::withAccountCodes()`
  and `BankTransactionLine::withAccountCode()` are the pieces. The fake does the same.
- The lost-response recovery is strict about reach: a change with a coding that matches no
  line on the read (a line that is not there, or an all-lines coding on a transaction with no
  lines) is never taken as landed (`BankTransactionChange::isSatisfiedBy()`).
- `RecodeInvariants::movedMoney()` reports a line tax that appeared or vanished, not only one
  that changed amount.
- A recode of a transaction whose type the connector has no case for is refused before any
  request with `ValidationException::REASON_TYPE_UNKNOWN` (`type_unknown`); the replacing write
  sends the read's own type and never defaults it to SPEND. A recognised type that is not SPEND
  or RECEIVE (a transfer, overpayment or prepayment leg) is refused with
  `REASON_TYPE_NOT_RECODABLE` (`type_not_recodable`): only the two matchable types are ever
  recoded (invariant 12 at the package boundary).
- `RequestGate::observe(HttpResponse, ?Provider, ?string $tenantId)` is called by `HttpClient`
  for every response it receives, retried 429s and 5xx included, before its own listeners; a gate
  that throws there is logged and ignored. `NullRequestGate` implements it as a no-op. A host that
  binds a gate no longer has to register an `afterResponse()` listener for the gate's bookkeeping.
- `AccountingConnector::LOOKUP_CHART_OF_ACCOUNTS` (`chart_of_accounts_v2`) is the lookup key both
  connectors store the chart of accounts under. Versioned because rows stored before
  `system_account` existed rehydrate without the flag; hosts reading the store must use the constant.
- The QuickBooks connector refuses a RECEIVE expense on an update as it already did on a create,
  before the SyncToken read.
- `FakeConnector::flush()` also clears a queued `afterNextBankTransactionCall()` callback.
- `CodesBankTransactions::updateBankTransactionCoding()` documents the `InvalidPayloadException`
  it throws when the codings would change nothing.

### Added

- A test that a two-line exclusive-tax bank transaction round-trips every line's Quantity,
  UnitAmount (four places), LineAmount, TaxType and the LineAmountTypes through a recode of one
  line and its revert, with the totals never on the wire (`XeroRecodeTest`). Nothing changed in
  the connector; the case was the one invariant 15 of the host's plan had no proof for before
  v0.4.0.
- Bank transaction reads carry what a safe write needs back. `BankTransactionData` gains
  `lineAmountType` (Xero defaults an omitted mode to Inclusive on bank transactions, so a recode
  that dropped it moved an exclusive-tax total by the tax) and `currencyRate`;
  `BankTransactionLine` gains `unitAmountExact` (the wire figure to four places, read with
  `unitdp=4`, because a price rounded to cents times a quantity is a different line), `taxAmount`
  and `itemCode`; `Account` gains `systemAccount` and `isSystem()`.
- `CodesBankTransactions::recodeBankTransaction(Connection, string, BankTransactionChange,
  ?RecodeExpectation, ?string $idempotencyKey): RecodeResult`, with three guards in order: an
  expectation that disagrees with the connector's own read throws `PreconditionFailedException`
  carrying the fresh copy and nothing is sent; a transaction whose tax mode is unknown or whose
  line tax was adjusted by hand (the BankTransactions endpoint ignores a supplied `TaxAmount`) is
  refused with a `ValidationException` whose new `reason` names which, and nothing is sent; after
  the write, any moved amount throws `RecodeMovedMoneyException` with both states. An empty
  change is refused before any request. `updateBankTransactionCoding` stays as a wrapper.
  `BankTransactionChange` carries codings and, optionally, a contact id; `RecodeResult` carries
  the connector's own before-state for a journal.
- `CodesBankTransactions::deleteBankTransaction(Connection, string, ?string $idempotencyKey): BankTransactionData`,
  a POST to the transaction's own URL with `Status: DELETED`. A 404, on the delete or on the
  re-read the connector makes when the response carries no row, comes back as a transaction
  with status DELETED rather than as an error, because a host deleting is often deleting
  something already gone; a 200 that still reports the transaction AUTHORISED raises. The
  fake sets the status, keeps the row for a later `find()`, and records `deleted[]`.
- The recode tax guard consults the customer's ARCHIVED tax rates (a second cached lookup,
  made only when an active rate is missing) before refusing a line as `tax_rate_unknown`,
  because catch-up transactions are routinely coded with rates since archived.
- `FindsContacts::findContactByName(Connection, string): ?Contact`, a read that never creates,
  implemented by the Xero connector and the fake (`withContacts()`).
- `BankTransactionQuery::order` and `pageSize` (default 250, configurable through
  `accounting-connector.bank_transactions.page_size`, capped at 1000); `BankTransactionPage`
  reads Xero's `pagination` object into `itemCount` and `pageCount` and prefers the page count
  over the full-page heuristic. `BankTransactionType::isMatchable()` and `direction()`, and the
  `MoneyDirection` enum: only SPEND and RECEIVE are ever match candidates.
- `ExpenseData::direction` (`MoneyDirection`, default `Out`). `In` posts as a Xero RECEIVE with
  the same positive amounts; the QuickBooks connector refuses it rather than posting a refund
  as a purchase.
- `RequestGate`, asked by `HttpClient` before every attempt, retries included, and
  `HttpClient::afterResponse()` for the remaining-call counters; the Laravel provider binds a
  `NullRequestGate` unless the host binds its own and builds the client over Guzzle with
  `timeout` and `connect_timeout`. `HttpClient::send()` takes the tenant id so a gate can key
  on it. `RequestGate::release(?Provider, ?string $tenantId, Throwable $failure)` is called for
  every admitted attempt that produced no response (a timeout, a reset, a DNS miss), so a gate
  that reserves an in-flight slot per attempt gets it back instead of waiting for it to expire;
  a gate that throws from `release()` is logged and ignored.
- `FakeConnector::afterNextBankTransactionCall(Closure)` runs a callback once, right after the
  next bank transaction list is answered, so a test can change the books between two pages of
  one walk and exercise a host's completeness check.
- `FakeConnector::resolveContact()` answers the same id for the same contact within one fake,
  as the real connectors' entity map does, so a host that asks again after a create reads the
  id the create used.
- `FakeConnector` orders by `Date` and `UpdatedDateUTC`, honours `pageSize`, fills the pagination
  counts, records `changes[]` and the direction of every created expense, and gains
  `nextRecodeReturns()` and `mutateBeforeNextRecode()` so a host can exercise the moved-money
  and stale-expectation paths.

### Changed

- The rate-limit prose throughout said Xero's per-minute ceiling was shared with the customer's
  other apps. It is not: Xero meters each app per organisation. Corrected in the connector,
  query, lookup store, config and README.
- Bank transaction lists send `pageSize`, `unitdp=4` and, when asked, `order`; single reads send
  `unitdp=4`.

### Previously in Unreleased

- `ReadsBankTransactions`, an optional interface carrying
  `listBankTransactions(Connection, BankTransactionQuery): BankTransactionPage` and
  `findBankTransaction(Connection, string): ?BankTransactionData`. Implemented by `XeroConnector`
  over `GET /BankTransactions`, with the query's type, status, date range and bank account folded
  into Xero's `where` expression and `modifiedSince` sent as an `If-Modified-Since` header in UTC
  (Xero compares it against `UpdatedDateUTC`, so a host in a positive offset that sent local time
  would ask for the future and get nothing back forever). A `304` is read as an empty page rather
  than a failure, and a `404` on a single transaction returns null, because a host asking about a
  transaction it mirrored last night is asking precisely because it may have been deleted.
  Separate from `AccountingConnector` because it is genuinely optional: a host asks with
  `instanceof` and falls back to posting.
- `CodesBankTransactions`, carrying
  `updateBankTransactionCoding(Connection, string, array<LineCoding>): BankTransactionData`. Xero
  has no partial update - a POST replaces the transaction and a field left out is a field cleared
  - so the implementation reads the transaction, lays the codings over its lines by `LineItemID`,
  and posts the whole thing back with the amounts, date, contact, bank account, reference, status
  and currency exactly as they came. Nothing about a reconciled transaction is pre-empted: Xero's
  spec documents `IsReconciled` as a read flag and states no restriction on updating one, so the
  call is made and a refusal surfaces as a `ValidationException` for the host to record.
- `BankTransactionData`, `BankTransactionLine`, `BankTransactionQuery`, `BankTransactionPage`,
  `LineCoding` and the `BankTransactionType` enum. Amounts are integer minor units like everything
  else that crosses this boundary; `BankTransactionData::isCoded()` and `accountCodes()` answer
  the one question a matcher has to ask before pushing its own coding over a bookkeeper's.
  `BankTransactionLine` is deliberately not `LineItem`: a write payload refuses to exist without
  an amount, and a read has to be able to represent a line the customer coded to nothing.
- `FakeConnector` implements both new interfaces. `withBankTransactions(...)` stocks its books,
  `failNextBankTransactionCall()` fails one call, `failNextRecoding()` fails only a coding change so a
  host can exercise the branch where the read succeeds and the write is refused, and the recorded
  `$bankTransactionQueries` and
  `$recodings` arrays are public. Its filtering is real rather than a stub returning everything,
  so a host test that asks for spend in a date window cannot pass against the fake and fail
  against Xero. A recoding is kept, so a later `findBankTransaction()` answers with it and a
  match-then-recode flow is testable end to end. `flush()` clears all of it.

- `resolveContact(ContactData $contact, Connection $connection): string` is now part of the
  `AccountingConnector` interface. Both shipped connectors already had exactly this method, so
  nothing about their behaviour changes; what changes is that a host can resolve a vendor to its
  provider id, ahead of any document, without type-hinting the concrete `XeroConnector` and
  losing the ability to swap in a test double for the whole sync path.
- `FakeConnector::resolveContact()`, recording each call in the public `$contacts` array and
  returning `fake-contact-1`, `fake-contact-2` and so on. `nextContactId('contact-7', ...)` queues
  specific ids for the next resolutions, a queued `failNextCreate()` fails a resolution the way it
  fails a create (resolving is how the real connectors create a contact), and an entity type
  refused with `doesNotSupport()` is refused here too. `flush()` clears both.
- `owner_id` on the `accounting_entity_map` migration stub: a nullable string, indexed as
  `(provider, owner_id, entity_type)`. `DatabaseEntityMap::remember()` writes it from
  `Connection::$reference`, storing null when the connection carries no reference, and refreshes
  it whenever the mapping is written again. It is written and never read: every lookup stays
  scoped to `(provider, tenant_id, entity_type)` exactly as before, because a mapping belongs to
  the tenant rather than to whoever posted it, and scoping by owner would re-create the same
  entity for a second owner on the same tenant. It is there so a row can be traced back to the
  tenant it was posted for. The `EntityMap` contract is unchanged, so a host implementation of it
  needs no work; a host that already published the migration adds the column with its own
  migration.
- Wire tests for the three Xero write paths that had none: creating a manual journal (`POST
  ManualJournals`, `POSTED` status, id read from `ManualJournals.0.ManualJournalID`), updating an
  entity (`POST {resource}/{id}` with the complete body and the id inside it), and revoking
  (`GET /connections`, then `DELETE /connections/{id}` for the entry matching the tenant, and a
  false return rather than a throw when the deletion fails). New doc-derived fixture
  `tests/Fixtures/xero/manual-journal-created.json`.

## [0.1.0] - 2026-08-28

First public release.

### Fixed

- QuickBooks invoice lines now post to the intended income account. The mapper sent
  `ItemAccountRef`, which the live API ignores: the line quietly attached to the company's
  default item and the income landed in whatever account that item is wired to. The connector
  now resolves each line's income account to a real `ItemRef`, find-or-creating a Service item
  named after the account (suffixed with the account id on a duplicate-name collision), cached
  through the same lookup machinery as the chart of accounts. Verified against a live sandbox.
- QuickBooks documents now carry `GlobalTaxCalculation`, mapped from the payload's
  `LineAmountType`. It was silently omitted, so a tax-inclusive bill, purchase or invoice posted
  to a non-US QuickBooks company was treated as tax-exclusive and its total came out off by the
  tax amount. Sandbox-verified on a US company: `TaxExcluded` is accepted (and dropped),
  `TaxInclusive` is rejected loudly with Intuit's "Inclusive Tax Type is not allowed" — the
  correct failure, where the old behaviour posted a wrong total silently.
- `DatabaseConnectionRepository::persist()` now logs an error when its update matches no stored
  row. A refresh whose tokens go nowhere used to be silent, and Intuit has already retired the
  refresh token they replaced, so the connection died days later with no obvious cause.
- The Xero connector now refuses a token refresh that returns 200 with no access token in it,
  matching the QuickBooks connector, instead of building a connection around an empty bearer
  token that later surfaces as a misleading revocation.
- `FakeConnector::flush()` now clears a queued `failNextCreate` exception, a queued attachment
  result, and `doesNotSupport()` marks; a fake reused across tests could throw a stale failure.
- `FakeConnector` now makes the same refusals as the real connectors — unsupported entity types,
  payload/entity-type mismatches, and raw payloads built for another provider — so a host test
  that passes the wrong payload fails in the test, not in production.
- Republishing the migrations no longer duplicates them: an already-published migration keeps its
  filename and is overwritten in place, instead of gaining a second timestamped copy that fails
  the next `migrate` on a table that already exists.
- Xero's `PAYG` account type (per the OpenAPI spec) now classifies as a liability instead of
  falling through to `Other`.
- Xero `revoke()` refreshes an expired access token before attempting the (still best-effort)
  revocation, so it no longer 401s every time it runs on a stale connection.
- The QuickBooks connector refuses a `paymentMethod` outside Cash / Check / CreditCard before the
  round trip; Intuit's own refusal names neither the field nor the allowed values.
- Both connectors' create/update dispatch switches gained explicit `default` branches that throw
  `UnsupportedEntityTypeException`, so a future `EntityType` case can never fall through to an
  implicit null that reads as "accepted but no id".

### Changed

- The Laravel service provider no longer binds `Psr\EventDispatcher\EventDispatcherInterface`
  app-wide; it binds the concrete `LaravelEventDispatcher` and hands that to the connectors. Any
  host that resolved the PSR-14 interface from the container to get this package's bridge should
  resolve `Hei\AccountingConnector\Laravel\LaravelEventDispatcher` instead.
- Provider responses in the test suite now come from doc-derived JSON fixtures under
  `tests/Fixtures/{xero,quickbooks}/`, built from the Xero OpenAPI spec and the Intuit API
  reference.

### Added

- `AccountingConnector` interface with `Xero` and `QuickBooksOnline` adapters, both over plain
  JSON REST via PSR-18. No vendored provider SDKs.
- Framework-free `Connection` object carrying provider, tenant id, tokens, expiry and
  per-connection settings, replacing the host's organization or company model at the seam.
- Canonical payload DTOs for both halves of the ledger: `ContactData` (customer and vendor),
  `InvoiceData`, `PaymentData`, `BillData`, `ExpenseData` and `JournalData`, with `LineItem`,
  `JournalLine`, `TrackingRef` and integer-cent `Money`.
- `RawPayload` escape hatch for provider-native payloads, with contact-name resolution so existing
  provider-shaped mappers can be ported one call site at a time.
- OAuth2 authorize, callback, refresh and revoke for both providers, with revocation detection and
  a `ConnectionRevoked` reconnect signal.
- `ConnectionStore` contract for persisting rotated tokens, with a `NullConnectionStore` that warns
  every time it discards a refresh.
- `ConnectionRepository` contract and `DatabaseConnectionRepository`, storing every connection in
  one `accounting_connections` table with tokens encrypted by the application key. Satisfies
  `ConnectionStore` too, so rotated QuickBooks refresh tokens persist with no host glue. Its
  `provider` column is not constrained to this package's providers, so a host can keep other
  integrations in the same table. A third provider costs a row rather than four more columns on a
  tenant model.
- `DatabaseLookupStore` over `accounting_connection_lookups`, one row per lookup key so concurrent
  refreshes cannot clobber each other, keeping the connection row small enough that a sync job does
  not drag a chart of accounts to read an access token.
- `EntityMap` contract with in-memory, null and Laravel database-backed implementations, plus a
  publishable migration stub. All lookups are scoped per tenant.
- `IdempotencyKey` helper that hashes rather than truncates when a key exceeds a provider's limit,
  so two distinct posts cannot collide inside Intuit's 50-character `requestid`.
- Attachment upload with `AttachmentSet` fallback candidates, honouring Xero's 10 MB ceiling.
  Failures are reported, never thrown, so a failed upload cannot undo a successful post.
- Chart-of-accounts, bank-account, tax-code and tracking-category lookups, resolved through a
  PSR-16 cache, then a durable `LookupStore`, then the provider. A stored list is served when the
  provider is unreachable rather than returning an empty one.
- `AccountClass` enum normalising Xero and QuickBooks account vocabularies, with `Account::only()`
  for filtering, so one cached chart-of-accounts call serves expense, income and bank dropdowns.
- `TrackingCategory` and `TrackingOption`, Xero-only, with archived categories and options filtered
  and both sorted. QuickBooks returns an empty list rather than throwing.
- `refreshLookups()` to force a re-fetch of everything a provider supports.
- `LookupStore` contract with `NullLookupStore` and `ArrayLookupStore` implementations.
- PSR-14 sync events: `EntityCreated`, `EntityCreateFailed`, `AttachmentUploaded`,
  `TokensRefreshed` and `ConnectionRevoked`, each with a credential-free `context()`.
- `HttpClient` with `Retry-After` handling on 429, full-jitter backoff on 5xx and transport
  failures, no retries on 4xx, and Xero rate-limit headers surfaced on every response.
- `XeroDate` parser for Xero's `/Date(1518685950940+0000)/` JSON response format.
- Laravel bridge: service provider, config, facade, PSR-14 event adapter and `DatabaseEntityMap`.
- `FakeConnector` and `FakeHttpClient` test doubles for consuming applications.
