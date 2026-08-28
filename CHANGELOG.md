# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

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
