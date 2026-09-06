<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Testing;

use Hei\AccountingConnector\Contracts\AccountingConnector;
use Hei\AccountingConnector\Contracts\CodesBankTransactions;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Contracts\ReadsBankTransactions;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\AttachmentSet;
use Hei\AccountingConnector\Data\AuthorizationResult;
use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionLine;
use Hei\AccountingConnector\Data\BankTransactionPage;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\TaxCode;
use Hei\AccountingConnector\Data\TenantInfo;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Data\TrackingCategory;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\NotFoundException;
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
final class FakeConnector implements AccountingConnector, CodesBankTransactions, ReadsBankTransactions
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

    /**
     * The bank transactions this fake pretends the customer's books hold.
     *
     * Keyed by id so a recoding can replace one in place and a later find() sees it,
     * which is what makes a match-then-recode flow testable end to end.
     *
     * @var array<string, BankTransactionData>
     */
    public array $bankTransactions = [];

    /** @var array<int, array{query: BankTransactionQuery, connection: Connection}> */
    public array $bankTransactionQueries = [];

    /** @var array<int, array{external_id: string, codings: array<int, LineCoding>}> */
    public array $recodings = [];

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

    /** Thrown by the next bank transaction call, then cleared. */
    private ?Throwable $nextBankTransactionFailure = null;

    /** Thrown by the next coding change only, then cleared. */
    private ?Throwable $nextRecodingFailure = null;

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
     * Stock the fake's books with bank transactions a matcher can find.
     */
    public function withBankTransactions(BankTransactionData ...$transactions): self
    {
        foreach ($transactions as $transaction) {
            $this->bankTransactions[$transaction->id] = $transaction;
        }

        return $this;
    }

    /**
     * Make the next bank transaction call throw.
     */
    public function failNextBankTransactionCall(Throwable $exception): self
    {
        $this->nextBankTransactionFailure = $exception;

        return $this;
    }

    /**
     * Make the next coding change throw, leaving reads working.
     *
     * The case a host has to get right: a match is decided, the transaction is read
     * back successfully, and only the recoding is refused. Failing every bank
     * transaction call instead would fail the read and test nothing.
     */
    public function failNextRecoding(Throwable $exception): self
    {
        $this->nextRecodingFailure = $exception;

        return $this;
    }

    /**
     * Bank transactions matching the query, filtered here rather than at a provider.
     *
     * The filtering is real, not a stub returning everything: a host test that asks
     * for spend in a date window and gets a receive from last year back would pass
     * against a fake that ignored the query and fail against Xero.
     */
    public function listBankTransactions(Connection $connection, BankTransactionQuery $query): BankTransactionPage
    {
        $this->guardBankTransactions();

        $this->bankTransactionQueries[] = ['query' => $query, 'connection' => $connection];

        $matches = array_values(array_filter(
            $this->bankTransactions,
            fn (BankTransactionData $transaction): bool => $this->matchesQuery($transaction, $query),
        ));

        $offset = (max(1, $query->page) - 1) * BankTransactionQuery::PAGE_SIZE;

        return new BankTransactionPage(
            array_slice($matches, $offset, BankTransactionQuery::PAGE_SIZE),
            $query->page,
        );
    }

    public function findBankTransaction(Connection $connection, string $externalId): ?BankTransactionData
    {
        $this->guardBankTransactions();

        return $this->bankTransactions[$externalId] ?? null;
    }

    /**
     * Apply coding and keep the result, so a later find() answers with it.
     *
     * @param  array<int, LineCoding>  $codings
     */
    public function updateBankTransactionCoding(
        Connection $connection,
        string $externalId,
        array $codings,
    ): BankTransactionData {
        $this->guardBankTransactions();

        if ($this->nextRecodingFailure !== null) {
            $failure = $this->nextRecodingFailure;
            $this->nextRecodingFailure = null;

            throw $failure;
        }

        $current = $this->bankTransactions[$externalId] ?? null;

        if ($current === null) {
            throw new NotFoundException(
                "The fake connector holds no bank transaction {$externalId}.",
                $this->provider,
            );
        }

        $this->recodings[] = ['external_id' => $externalId, 'codings' => $codings];

        $lines = array_map(
            function (BankTransactionLine $line) use ($codings): BankTransactionLine {
                $accountCode = $line->accountCode;
                $tracking = $line->tracking;

                foreach ($codings as $coding) {
                    if (! $coding->appliesTo($line->lineItemId)) {
                        continue;
                    }

                    $accountCode = $coding->accountCode ?? $accountCode;
                    $tracking = $coding->tracking ?? $tracking;
                }

                return new BankTransactionLine(
                    lineItemId: $line->lineItemId,
                    description: $line->description,
                    quantity: $line->quantity,
                    unitAmount: $line->unitAmount,
                    lineAmount: $line->lineAmount,
                    accountCode: $accountCode,
                    accountId: $line->accountId,
                    taxType: $line->taxType,
                    tracking: $tracking,
                );
            },
            $current->lines,
        );

        $recoded = new BankTransactionData(
            id: $current->id,
            type: $current->type,
            date: $current->date,
            total: $current->total,
            subTotal: $current->subTotal,
            totalTax: $current->totalTax,
            currency: $current->currency,
            status: $current->status,
            contactId: $current->contactId,
            contactName: $current->contactName,
            bankAccountId: $current->bankAccountId,
            bankAccountName: $current->bankAccountName,
            reference: $current->reference,
            isReconciled: $current->isReconciled,
            hasAttachments: $current->hasAttachments,
            lines: $lines,
            updatedDateUtc: $current->updatedDateUtc,
        );

        $this->bankTransactions[$externalId] = $recoded;

        return $recoded;
    }

    private function matchesQuery(BankTransactionData $transaction, BankTransactionQuery $query): bool
    {
        if ($query->type !== null && $transaction->type !== $query->type) {
            return false;
        }

        if ($query->status !== null && strcasecmp((string) $transaction->status, $query->status) !== 0) {
            return false;
        }

        if ($query->bankAccountId !== null && $transaction->bankAccountId !== $query->bankAccountId) {
            return false;
        }

        if ($query->from !== null && ($transaction->date === null || $transaction->date < $query->from)) {
            return false;
        }

        if ($query->to !== null && ($transaction->date === null || $transaction->date > $query->to)) {
            return false;
        }

        if ($query->modifiedSince !== null
            && $transaction->updatedDateUtc !== null
            && $transaction->updatedDateUtc < $query->modifiedSince) {
            return false;
        }

        return true;
    }

    /**
     * @throws Throwable when the fake has been told the next call fails
     */
    private function guardBankTransactions(): void
    {
        if ($this->nextBankTransactionFailure !== null) {
            $failure = $this->nextBankTransactionFailure;
            $this->nextBankTransactionFailure = null;
            $this->nextRecodingFailure = null;

            throw $failure;
        }
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
        $this->bankTransactions = [];
        $this->bankTransactionQueries = [];
        $this->recodings = [];
        $this->nextBankTransactionFailure = null;
    }
}
