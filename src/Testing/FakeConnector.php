<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Testing;

use Hei\AccountingConnector\Contracts\AccountingConnector;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\AttachmentSet;
use Hei\AccountingConnector\Data\AuthorizationResult;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\TaxCode;
use Hei\AccountingConnector\Data\TenantInfo;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Data\TrackingCategory;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\UnsupportedEntityTypeException;
use Throwable;

/**
 * An in-memory connector for the applications that consume this package.
 *
 * Bind it in place of the real one and the whole sync path becomes testable with no
 * HTTP faking at all. It records every call, hands back predictable ids, and can be
 * told to fail so the error branches get covered too.
 *
 *     $fake = new FakeConnector(Provider::Xero);
 *     $manager->set(Provider::Xero, $fake);
 *
 *     // ... exercise the app ...
 *
 *     expect($fake->created)->toHaveCount(1);
 *     expect($fake->createdOf(EntityType::Bill))->toHaveCount(1);
 */
final class FakeConnector implements AccountingConnector
{
    /** @var array<int, array{type: EntityType, payload: EntityPayload, connection: Connection, idempotency_key: string|null}> */
    public array $created = [];

    /** @var array<int, array{type: EntityType, external_id: string, payload: EntityPayload}> */
    public array $updated = [];

    /** @var array<int, array{type: EntityType, external_id: string, attachment: AttachmentSet}> */
    public array $attached = [];

    /** @var array<int, array{contact: ContactData, connection: Connection}> */
    public array $contacts = [];

    /** @var array<int, Connection> */
    public array $refreshed = [];

    /** @var array<int, Account> */
    public array $accounts = [];

    /** @var array<int, Account> */
    public array $banks = [];

    /** @var array<int, TaxCode> */
    public array $taxes = [];

    /** @var array<int, TrackingCategory> */
    public array $tracking = [];

    /** How many times refreshLookups() was called. */
    public int $lookupRefreshes = 0;

    public ?TenantInfo $tenant = null;

    /** Thrown by the next create, then cleared. */
    private ?Throwable $nextFailure = null;

    /** Returned by the next attach, then cleared. */
    private ?AttachmentResult $nextAttachmentResult = null;

    /** Makes the next create return null, then clears. */
    private bool $nextCreateReturnsNoId = false;

    /**
     * Ids handed back by the next contact resolutions, one each, then exhausted.
     *
     * @var array<int, string>
     */
    private array $contactIds = [];

    /** Makes tenantInfo() report an unknown tenant. */
    private bool $tenantUnknown = false;

    /** Thrown by every lookup until cleared. */
    private ?Throwable $lookupFailure = null;

    private int $sequence = 0;

    /** @var array<string, bool> */
    private array $unsupported = [];

    public function __construct(
        private readonly Provider $provider = Provider::Xero,
    ) {}

    public function provider(): Provider
    {
        return $this->provider;
    }

    public function supports(EntityType $type): bool
    {
        return ! isset($this->unsupported[$type->value]);
    }

    /**
     * Make this fake refuse an entity type, so the unsupported branch gets exercised.
     */
    public function doesNotSupport(EntityType ...$types): self
    {
        foreach ($types as $type) {
            $this->unsupported[$type->value] = true;
        }

        return $this;
    }

    /**
     * Make the next create throw.
     */
    public function failNextCreate(Throwable $exception): self
    {
        $this->nextFailure = $exception;

        return $this;
    }

    /**
     * Make the next create succeed at the provider but return no id.
     *
     * A real path: both providers can accept a request and answer with a body the
     * id is missing from, and the host has to cope with a null.
     */
    public function nextCreateReturnsNoId(): self
    {
        $this->nextCreateReturnsNoId = true;

        return $this;
    }

    /**
     * Queue the ids the next contact resolutions return.
     *
     * One id per call, in order, then the fake goes back to its own sequence. For
     * a host test that has to assert against an id the provider already holds.
     */
    public function nextContactId(string ...$ids): self
    {
        foreach ($ids as $id) {
            $this->contactIds[] = $id;
        }

        return $this;
    }

    /**
     * Make every lookup throw, simulating a provider outage.
     *
     * Unlike failNextCreate() this persists until cleared, because a host usually
     * wants to prove that a whole settings page degrades rather than that one call
     * did. Pass null to clear it.
     */
    public function failLookups(?Throwable $exception): self
    {
        $this->lookupFailure = $exception;

        return $this;
    }

    /**
     * Make tenantInfo() report that it could not identify the company.
     */
    public function withoutTenantInfo(): self
    {
        $this->tenantUnknown = true;

        return $this;
    }

    /**
     * Make the next attach report a specific outcome.
     */
    public function nextAttachment(AttachmentResult $result): self
    {
        $this->nextAttachmentResult = $result;

        return $this;
    }

    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        return 'https://example.test/authorize?state='.urlencode($state);
    }

    public function exchangeCode(string $code, array $parameters = [], ?string $redirectUri = null): AuthorizationResult
    {
        return new AuthorizationResult(
            provider: $this->provider,
            tokens: new TokenSet(
                accessToken: 'fake-access-token',
                refreshToken: 'fake-refresh-token',
                expiresAt: (new \DateTimeImmutable)->modify('+30 minutes'),
            ),
            tenantId: 'fake-tenant',
        );
    }

    public function refresh(Connection $connection): Connection
    {
        $this->refreshed[] = $connection;

        return $connection->withTokens(new TokenSet(
            accessToken: 'refreshed-access-token-'.(++$this->sequence),
            refreshToken: 'refreshed-refresh-token-'.$this->sequence,
            expiresAt: (new \DateTimeImmutable)->modify('+30 minutes'),
        ));
    }

    public function revoke(Connection $connection): bool
    {
        return true;
    }

    public function tenantInfo(Connection $connection): ?TenantInfo
    {
        if ($this->tenantUnknown) {
            return null;
        }

        return $this->tenant ?? new TenantInfo(
            id: $connection->tenantId,
            name: 'Fake Company',
            currencyCode: 'USD',
        );
    }

    public function resolveContact(ContactData $contact, Connection $connection): string
    {
        // The real connectors resolve a contact by creating it when the provider
        // has never seen it, so this refuses what a create would refuse and honours
        // the same queued failure.
        $this->assertAcceptable($contact->role, $contact);

        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }

        $this->contacts[] = ['contact' => $contact, 'connection' => $connection];

        if ($this->contactIds !== []) {
            return (string) array_shift($this->contactIds);
        }

        return 'fake-contact-'.(++$this->sequence);
    }

    public function createEntity(
        EntityType $type,
        EntityPayload $payload,
        Connection $connection,
        ?string $idempotencyKey = null,
    ): ?string {
        $this->assertAcceptable($type, $payload);

        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }

        $this->created[] = [
            'type' => $type,
            'payload' => $payload,
            'connection' => $connection,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($this->nextCreateReturnsNoId) {
            $this->nextCreateReturnsNoId = false;

            return null;
        }

        return sprintf('fake-%s-%d', $type->value, ++$this->sequence);
    }

    public function updateEntity(
        EntityType $type,
        string $externalId,
        EntityPayload $payload,
        Connection $connection,
    ): bool {
        $this->assertAcceptable($type, $payload);

        $this->updated[] = ['type' => $type, 'external_id' => $externalId, 'payload' => $payload];

        return true;
    }

    /**
     * The same refusals the real connectors make, so a host test that passes the
     * wrong payload fails here rather than in production.
     */
    private function assertAcceptable(EntityType $type, EntityPayload $payload): void
    {
        if (! $this->supports($type)) {
            throw UnsupportedEntityTypeException::for($this->provider, $type);
        }

        if ($payload instanceof RawPayload) {
            $payload->assertMatches($this->provider);
        }

        if ($payload->entityType() !== $type) {
            throw new InvalidPayloadException(sprintf(
                'Cannot create a "%s": the payload describes a "%s".',
                $type->value,
                $payload->entityType()->value,
            ), $this->provider);
        }
    }

    public function attach(
        EntityType $type,
        string $externalId,
        Attachment|AttachmentSet $attachment,
        Connection $connection,
    ): AttachmentResult {
        $set = AttachmentSet::wrap($attachment);

        $this->attached[] = ['type' => $type, 'external_id' => $externalId, 'attachment' => $set];

        if ($this->nextAttachmentResult !== null) {
            $result = $this->nextAttachmentResult;
            $this->nextAttachmentResult = null;

            return $result;
        }

        $chosen = $set->candidates[0];

        return AttachmentResult::success(
            filename: $chosen->normalisedFilename(),
            bytes: $chosen->size(),
            externalId: 'fake-attachment-'.(++$this->sequence),
        );
    }

    public function chartOfAccounts(Connection $connection, bool $forceRefresh = false): array
    {
        $this->guardLookups();

        return $this->accounts;
    }

    public function bankAccounts(Connection $connection, bool $forceRefresh = false): array
    {
        $this->guardLookups();

        return $this->banks;
    }

    public function taxCodes(Connection $connection, bool $forceRefresh = false): array
    {
        $this->guardLookups();

        return $this->taxes;
    }

    public function trackingCategories(Connection $connection, bool $forceRefresh = false): array
    {
        $this->guardLookups();

        return $this->tracking;
    }

    public function refreshLookups(Connection $connection): void
    {
        $this->guardLookups();

        $this->lookupRefreshes++;
    }

    /**
     * @throws Throwable when the fake has been told lookups are failing
     */
    private function guardLookups(): void
    {
        if ($this->lookupFailure !== null) {
            throw $this->lookupFailure;
        }
    }

    /**
     * Everything created of one type, for assertions.
     *
     * @return array<int, array{type: EntityType, payload: EntityPayload, connection: Connection, idempotency_key: string|null}>
     */
    public function createdOf(EntityType $type): array
    {
        return array_values(array_filter(
            $this->created,
            fn (array $call): bool => $call['type'] === $type,
        ));
    }

    /**
     * Forget every recorded call.
     */
    public function flush(): void
    {
        $this->created = [];
        $this->updated = [];
        $this->attached = [];
        $this->contacts = [];
        $this->refreshed = [];
        $this->sequence = 0;
        $this->lookupRefreshes = 0;
        $this->nextCreateReturnsNoId = false;
        $this->tenantUnknown = false;
        $this->lookupFailure = null;
        $this->nextFailure = null;
        $this->nextAttachmentResult = null;
        $this->contactIds = [];
        $this->unsupported = [];
    }
}
