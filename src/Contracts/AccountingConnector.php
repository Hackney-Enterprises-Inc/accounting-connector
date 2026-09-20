<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\AttachmentSet;
use Hei\AccountingConnector\Data\AuthorizationResult;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\TaxCode;
use Hei\AccountingConnector\Data\TenantInfo;
use Hei\AccountingConnector\Data\TrackingCategory;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\ValidationException;

/**
 * One accounting system, behind one interface.
 *
 * The shape is descended from AccountingPipe's proven seam:
 *
 *     createEntity(string $entityType, array $entityData, Organization $org, ?string $idempotencyKey): ?string
 *
 * with the framework-shaped parts replaced. The Organization became a Connection,
 * which carries provider, tenant, tokens and per-connection settings and knows
 * nothing about companies or organizations. The string entity type became an enum.
 * The provider-shaped array became a canonical payload the connector translates.
 * The idempotency key survived unchanged, because it was already right.
 *
 * What a connector does NOT do, by design: it does not persist anything, own a
 * queue, render a document, decide whether something is ready to sync, or know what
 * a tenant is. Those belong to the application. See the README for the seam list.
 */
interface AccountingConnector
{
    /**
     * The lookup key every connector stores its chart of accounts under, in the
     * LookupStore and the PSR-16 cache alike.
     *
     * Versioned because a stored row outlives the shape of Account: rows written
     * before `system_account` existed rehydrate with the flag missing, and a guard
     * that keeps a system account out of a rule or a holding list would read them
     * as ordinary accounts until the row happened to be refreshed. Bumping the key
     * leaves the old rows behind and makes every reader fetch afresh once. A host
     * that asks the store about the chart (its last sync time, say) must use this
     * constant, never the literal.
     */
    public const LOOKUP_CHART_OF_ACCOUNTS = 'chart_of_accounts_v2';

    public function provider(): Provider;

    /**
     * Whether this provider can represent the entity type at all.
     *
     * Cheaper than catching UnsupportedEntityTypeException when the caller needs to
     * branch rather than fail.
     */
    public function supports(EntityType $type): bool;

    /**
     * Where to send the customer to authorise us.
     *
     * `$state` is a CSRF nonce the caller generates, stores, and checks on the way
     * back. The package deliberately does not manage it: it has no session.
     */
    public function authorizationUrl(string $state, ?string $redirectUri = null): string;

    /**
     * Trade a callback code for tokens.
     *
     * `$parameters` carries whatever else the callback delivered. Intuit puts the
     * realm id there as `realmId` and it is required; Xero sends nothing extra and
     * the connector fetches the tenant separately.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function exchangeCode(string $code, array $parameters = [], ?string $redirectUri = null): AuthorizationResult;

    /**
     * Renew the access token and hand the result to the bound ConnectionStore.
     *
     * Returns the refreshed connection. The caller must use the returned instance:
     * Connection is immutable, so the one that was passed in still holds the old,
     * now useless, access token.
     *
     * @throws ConnectionRevokedException when only a reconnect will fix it
     */
    public function refresh(Connection $connection): Connection;

    /**
     * Tell the provider to forget us.
     *
     * Best effort, and returns false rather than throwing on failure: the local
     * disconnect has to happen either way, or the customer is stuck holding
     * credentials they cannot drop.
     */
    public function revoke(Connection $connection): bool;

    /**
     * Which company we are actually connected to.
     *
     * Worth showing back to the customer right after connecting: a bookkeeper signed
     * in to six client organizations will otherwise connect the wrong one and not
     * find out until the first transaction lands in a stranger's ledger.
     */
    public function tenantInfo(Connection $connection): ?TenantInfo;

    /**
     * The provider's id for this contact, creating it if the provider has never
     * seen it.
     *
     * Part of the interface because hosts need it on its own, ahead of any
     * document: to show the customer which vendor a receipt will post against, and
     * to code a line before the bill exists. It is also what every create path uses
     * internally, so a host that had to reach for the concrete connector to call it
     * could not swap in a test double for the whole sync path.
     *
     * The entity map answers first, keyed on ContactData::mapKey(), so a repeat
     * post costs no round trip and does not re-match on a name the customer may
     * have edited since.
     *
     * @throws ValidationException when the provider refuses the contact
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function resolveContact(ContactData $contact, Connection $connection): string;

    /**
     * Create an entity and return the provider's id for it.
     *
     * Always pass an idempotency key for anything that moves money. Without one, a
     * timeout after the provider committed but before we saw the response is
     * indistinguishable from a failure, and the retry posts it twice. See
     * Support\IdempotencyKey. Pass null only when a second entity is genuinely
     * wanted, such as a user-initiated resync.
     *
     * @throws ValidationException on a payload the provider rejects
     * @throws ConnectionRevokedException when the connection is dead
     */
    public function createEntity(
        EntityType $type,
        EntityPayload $payload,
        Connection $connection,
        ?string $idempotencyKey = null,
    ): ?string;

    /**
     * Overwrite an entity that already exists.
     *
     * Both providers replace rather than merge on update, so a payload must be
     * complete. Sending only the fields that changed deletes the ones that did not.
     */
    public function updateEntity(
        EntityType $type,
        string $externalId,
        EntityPayload $payload,
        Connection $connection,
    ): bool;

    /**
     * Hang a file off a posted entity.
     *
     * Never throws. The entity already exists by the time this runs, so a failure
     * here must not be able to fail a job that would then retry and post twice.
     * Inspect the returned AttachmentResult.
     *
     * Pass an AttachmentSet rather than an Attachment to offer fallbacks: Xero caps
     * attachments at 10 MB and a rendered email PDF often exceeds it, so offering
     * the PNG rendering as a second candidate is the difference between an
     * attachment and no attachment.
     */
    public function attach(
        EntityType $type,
        string $externalId,
        Attachment|AttachmentSet $attachment,
        Connection $connection,
    ): AttachmentResult;

    /**
     * Every active account on the connected company, cached per connection.
     *
     * One call covers expense, income and bank dropdowns: each Account carries a
     * portable AccountClass, so filter with Account::only() rather than asking the
     * provider three separate questions against a shared per-minute rate ceiling.
     *
     * @return array<int, Account>
     */
    public function chartOfAccounts(Connection $connection, bool $forceRefresh = false): array;

    /**
     * Accounts money can be spent from or deposited into.
     *
     * A filtered view of chartOfAccounts(), so it costs no extra API call.
     *
     * @return array<int, Account>
     */
    public function bankAccounts(Connection $connection, bool $forceRefresh = false): array;

    /**
     * Tax rates available on this company, cached per connection.
     *
     * @return array<int, TaxCode>
     */
    public function taxCodes(Connection $connection, bool $forceRefresh = false): array;

    /**
     * Tracking categories and their options, cached per connection.
     *
     * Xero-only. QuickBooks has no equivalent and returns an empty list rather than
     * throwing, so a host rendering a tracking dropdown does not need to branch on
     * the provider.
     *
     * @return array<int, TrackingCategory>
     */
    public function trackingCategories(Connection $connection, bool $forceRefresh = false): array;

    /**
     * Re-fetch every lookup this provider supports, discarding what is held.
     *
     * Backs the "refresh accounts" button on a host's integrations page.
     */
    public function refreshLookups(Connection $connection): void;
}
