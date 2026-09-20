<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Testing;

use Closure;
use DateTimeImmutable;
use Hei\AccountingConnector\Contracts\AccountingConnector;
use Hei\AccountingConnector\Contracts\CodesBankTransactions;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Contracts\FindsContacts;
use Hei\AccountingConnector\Contracts\ReadsBankTransactions;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\AttachmentSet;
use Hei\AccountingConnector\Data\AuthorizationResult;
use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionLine;
use Hei\AccountingConnector\Data\BankTransactionPage;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\Contact;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\RecodeExpectation;
use Hei\AccountingConnector\Data\RecodeResult;
use Hei\AccountingConnector\Data\TaxCode;
use Hei\AccountingConnector\Data\TenantInfo;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Data\TrackingCategory;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\MoneyDirection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\NotFoundException;
use Hei\AccountingConnector\Exceptions\PreconditionFailedException;
use Hei\AccountingConnector\Exceptions\RecodeMovedMoneyException;
use Hei\AccountingConnector\Exceptions\UnsupportedEntityTypeException;
use Hei\AccountingConnector\Support\RecodeInvariants;
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
final class FakeConnector implements AccountingConnector, CodesBankTransactions, FindsContacts, ReadsBankTransactions
{
    /**
     * Every create, in order. `direction` is set for an expense so a host can assert a
     * refund went in as a RECEIVE without unpacking the payload.
     *
     * @var array<int, array{type: EntityType, payload: EntityPayload, connection: Connection, idempotency_key: string|null, direction: MoneyDirection|null}>
     */
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

    /**
     * Every coding-only recode, kept for hosts that assert on the old shape.
     *
     * @var array<int, array{external_id: string, codings: array<int, LineCoding>}>
     */
    public array $recodings = [];

    /**
     * Every change that went through recodeBankTransaction(), with what it carried.
     *
     * @var array<int, array{external_id: string, change: BankTransactionChange, expectation: RecodeExpectation|null, idempotency_key: string|null}>
     */
    public array $changes = [];

    /**
     * Names findContactByName() was asked for, in order.
     *
     * @var array<int, string>
     */
    public array $contactLookups = [];

    /**
     * Every delete, with the idempotency key it carried.
     *
     * @var array<int, array{external_id: string, idempotency_key: string|null}>
     */
    public array $deleted = [];

    /** @var array<string, Contact> keyed by lowercased name */
    private array $knownContacts = [];

    /** Returned by the next recode instead of the applied change, then cleared. */
    private ?BankTransactionData $nextRecodeResult = null;

    /** @var Closure(BankTransactionData|null): (BankTransactionData|null)|null */
    private ?Closure $mutateBeforeNextRecode = null;

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

    /**
     * Contact ids already handed out, by tenant, role and map key.
     *
     * @var array<string, string>
     */
    private array $resolvedContactIds = [];

    /** Makes tenantInfo() report an unknown tenant. */
    private bool $tenantUnknown = false;

    /** Thrown by every lookup until cleared. */
    private ?Throwable $lookupFailure = null;

    /** Thrown by the next bank transaction call, then cleared. */
    private ?Throwable $nextBankTransactionFailure = null;

    /** @var (Closure(): void)|null */
    private ?Closure $afterNextBankTransactionList = null;

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
                expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
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
            expiresAt: (new DateTimeImmutable)->modify('+30 minutes'),
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

        // Like the real connectors' entity map: the same contact resolves to the
        // same id every time, so a host that asks again after a create gets the id
        // the create used rather than a fresh one.
        $key = $connection->tenantId.'|'.$contact->role->value.'|'.$contact->mapKey();

        if ($this->contactIds !== []) {
            return $this->resolvedContactIds[$key] = (string) array_shift($this->contactIds);
        }

        return $this->resolvedContactIds[$key] ??= 'fake-contact-'.(++$this->sequence);
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
            'direction' => $payload instanceof ExpenseData ? $payload->direction : null,
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
     * Run something once, right after the next bank transaction list is answered.
     *
     * The way to change the books BETWEEN two pages of one walk: a row that lands
     * while a host is paging moves the page boundary and Xero's item count, which
     * is exactly what a completeness check has to notice. Two separate runs cannot
     * model it; this can.
     *
     * @param  Closure(): void  $callback
     */
    public function afterNextBankTransactionCall(Closure $callback): self
    {
        $this->afterNextBankTransactionList = $callback;

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

        $this->order($matches, $query->order);

        $pageSize = $query->effectivePageSize();
        $offset = ($query->page - 1) * $pageSize;

        // The same pagination object Xero returns, so a host's completeness check
        // is exercised against the fake exactly as it will be against the provider.
        $page = new BankTransactionPage(
            array_slice($matches, $offset, $pageSize),
            $query->page,
            $pageSize,
            itemCount: count($matches),
            pageCount: (int) ceil(count($matches) / $pageSize),
        );

        if ($this->afterNextBankTransactionList !== null) {
            $callback = $this->afterNextBankTransactionList;
            $this->afterNextBankTransactionList = null;
            $callback();
        }

        return $page;
    }

    /**
     * Sort like Xero would, for the two fields a walk orders by.
     *
     * `Date` and `UpdatedDateUTC`, ascending or descending, with the id as the
     * tiebreak Xero applies itself. Nothing asked for means Xero's default,
     * `UpdatedDateUTC ASC`. Any other field is left in insertion order rather than
     * guessed at, so a host that orders by something this fake cannot honour sees
     * it in its test rather than in production.
     *
     * @param  array<int, BankTransactionData>  $rows
     */
    private function order(array &$rows, ?string $order): void
    {
        [$field, $direction] = array_pad(explode(' ', $order ?? 'UpdatedDateUTC ASC', 2), 2, 'ASC');

        $key = match (strtolower($field)) {
            'date' => static fn (BankTransactionData $row): string => $row->date?->format('Y-m-d') ?? '',
            'updateddateutc' => static fn (BankTransactionData $row): string => $row->updatedDateUtc?->format('Y-m-d H:i:s.u') ?? '',
            default => null,
        };

        if ($key === null) {
            return;
        }

        $sign = strtoupper($direction) === 'DESC' ? -1 : 1;

        usort($rows, static function (BankTransactionData $a, BankTransactionData $b) use ($key, $sign): int {
            $byField = strcmp($key($a), $key($b));

            return $byField !== 0 ? $sign * $byField : $sign * strcmp($a->id, $b->id);
        });
    }

    public function findBankTransaction(Connection $connection, string $externalId): ?BankTransactionData
    {
        $this->guardBankTransactions();

        return $this->bankTransactions[$externalId] ?? null;
    }

    /**
     * Apply a change and keep the result, so a later find() answers with it.
     *
     * The guards are the real connector's, minus the tax arithmetic: the fake has
     * no rates, so a host exercises the tax refusal with failNextRecoding() and a
     * ValidationException carrying the reason. The expectation and the moved-money
     * check are real, because they are what a host's own tests need to hit.
     */
    public function recodeBankTransaction(
        Connection $connection,
        string $externalId,
        BankTransactionChange $change,
        ?RecodeExpectation $expectation = null,
        ?string $idempotencyKey = null,
    ): RecodeResult {
        $this->guardBankTransactions();

        if ($change->isEmpty()) {
            throw new InvalidPayloadException(
                "A recode of {$externalId} that changes nothing was refused before any request.",
                $this->provider,
            );
        }

        if ($this->nextRecodingFailure !== null) {
            $failure = $this->nextRecodingFailure;
            $this->nextRecodingFailure = null;

            throw $failure;
        }

        // What the "connector's own read" finds: the stored transaction, after any
        // change a test staged to land between the host's read and this one.
        if ($this->mutateBeforeNextRecode !== null) {
            $mutate = $this->mutateBeforeNextRecode;
            $this->mutateBeforeNextRecode = null;

            $mutated = $mutate($this->bankTransactions[$externalId] ?? null);

            if ($mutated instanceof BankTransactionData) {
                $this->bankTransactions[$mutated->id] = $mutated;
            }
        }

        $current = $this->bankTransactions[$externalId] ?? null;

        if ($current === null) {
            throw new NotFoundException(
                "The fake connector holds no bank transaction {$externalId}.",
                $this->provider,
            );
        }

        if ($expectation !== null) {
            $differences = $expectation->differences($current);

            // As the real connector: a read that already shows the change applied
            // is an earlier attempt whose response was lost, not a stale decision.
            if ($differences !== [] && $change->isSatisfiedBy($current)
                && $change->codesAfter($expectation->accountCodesByLine) === $current->accountCodesByLine()
                && ! ($expectation->updatedDateUtc !== null && $current->updatedDateUtc !== null && $current->updatedDateUtc < $expectation->updatedDateUtc)) {
                return new RecodeResult(
                    $current->withAccountCodes($expectation->accountCodesByLine, $expectation->updatedDateUtc),
                    $current,
                    recovered: true,
                );
            }

            if ($differences !== []) {
                throw new PreconditionFailedException(
                    sprintf(
                        'The bank transaction %s changed since the recode was decided: %s.',
                        $externalId,
                        implode('; ', $differences),
                    ),
                    $current,
                    $differences,
                    $this->provider,
                );
            }
        }

        $this->changes[] = [
            'external_id' => $externalId,
            'change' => $change,
            'expectation' => $expectation,
            'idempotency_key' => $idempotencyKey,
        ];
        $this->recodings[] = ['external_id' => $externalId, 'codings' => $change->codings];

        $after = $this->nextRecodeResult ?? $this->applyChange($current, $change);
        $this->nextRecodeResult = null;

        $moved = RecodeInvariants::movedMoney($current, $after);

        if ($moved !== []) {
            // The write has "landed" in the fake's books exactly as it would have
            // in Xero's, so a host sees the state a person would.
            $this->bankTransactions[$externalId] = $after;

            throw new RecodeMovedMoneyException(
                sprintf('Recoding the bank transaction %s moved money: %s.', $externalId, implode('; ', $moved)),
                $current,
                $after,
                $moved,
                $this->provider,
            );
        }

        $this->bankTransactions[$externalId] = $after;

        return new RecodeResult($current, $after);
    }

    /**
     * The coding-only wrapper, like the real connector's.
     *
     * @param  array<int, LineCoding>  $codings
     */
    public function updateBankTransactionCoding(
        Connection $connection,
        string $externalId,
        array $codings,
    ): BankTransactionData {
        return $this->recodeBankTransaction($connection, $externalId, new BankTransactionChange($codings))->after;
    }

    /**
     * Delete by status, keeping the row so a later find() shows it DELETED.
     *
     * Xero may answer a GET after a delete with a 404 or with the row and its new
     * status; the fake does the second so a host's "already gone" branch and its
     * "gone, but still listed" branch are both reachable from one fixture. An id the
     * fake never held answers as the real connector answers a 404: a placeholder
     * with status DELETED.
     */
    public function deleteBankTransaction(Connection $connection, string $externalId, ?string $idempotencyKey = null): BankTransactionData
    {
        $this->guardBankTransactions();

        $this->deleted[] = ['external_id' => $externalId, 'idempotency_key' => $idempotencyKey];

        $current = $this->bankTransactions[$externalId] ?? null;

        if ($current === null) {
            return new BankTransactionData(
                id: $externalId,
                type: null,
                date: null,
                total: Money::zero(),
                status: 'DELETED',
            );
        }

        $deleted = new BankTransactionData(
            id: $current->id,
            type: $current->type,
            date: $current->date,
            total: $current->total,
            subTotal: $current->subTotal,
            totalTax: $current->totalTax,
            currency: $current->currency,
            status: 'DELETED',
            contactId: $current->contactId,
            contactName: $current->contactName,
            bankAccountId: $current->bankAccountId,
            bankAccountName: $current->bankAccountName,
            reference: $current->reference,
            isReconciled: $current->isReconciled,
            hasAttachments: $current->hasAttachments,
            lines: $current->lines,
            updatedDateUtc: new DateTimeImmutable,
            lineAmountType: $current->lineAmountType,
            currencyRate: $current->currencyRate,
        );

        $this->bankTransactions[$externalId] = $deleted;

        return $deleted;
    }

    /**
     * Stock the fake with contacts findContactByName() can answer with.
     */
    public function withContacts(Contact ...$contacts): self
    {
        foreach ($contacts as $contact) {
            $this->knownContacts[strtolower(trim($contact->name))] = $contact;
        }

        return $this;
    }

    public function findContactByName(Connection $connection, string $name): ?Contact
    {
        $this->guardLookups();

        $this->contactLookups[] = $name;

        return $this->knownContacts[strtolower(trim($name))] ?? null;
    }

    /**
     * Make the next recode come back with this transaction, whatever was asked.
     *
     * For the moved-money path: hand back a copy with a different total and the
     * fake raises RecodeMovedMoneyException exactly as the real connector would.
     */
    public function nextRecodeReturns(BankTransactionData $transaction): self
    {
        $this->nextRecodeResult = $transaction;

        return $this;
    }

    /**
     * Change the stored transaction between the host's read and the connector's.
     *
     * The closure gets the stored transaction (or null) and returns the one the
     * connector will find. A host test uses it to prove a stale expectation is
     * refused: read, stage a change here, then recode with the expectation from the
     * read.
     *
     * @param  Closure(BankTransactionData|null): (BankTransactionData|null)  $mutate
     */
    public function mutateBeforeNextRecode(Closure $mutate): self
    {
        $this->mutateBeforeNextRecode = $mutate;

        return $this;
    }

    /**
     * The transaction as it stands after a change, coded and re-contacted.
     */
    private function applyChange(BankTransactionData $current, BankTransactionChange $change): BankTransactionData
    {
        $lines = array_map(
            function (BankTransactionLine $line) use ($change): BankTransactionLine {
                $accountCode = $line->accountCode;
                $tracking = $line->tracking;

                foreach ($change->codings as $coding) {
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
                    unitAmountExact: $line->unitAmountExact,
                    taxAmount: $line->taxAmount,
                    itemCode: $line->itemCode,
                );
            },
            $current->lines,
        );

        $contactId = $change->contactId ?? $current->contactId;

        return new BankTransactionData(
            id: $current->id,
            type: $current->type,
            date: $current->date,
            total: $current->total,
            subTotal: $current->subTotal,
            totalTax: $current->totalTax,
            currency: $current->currency,
            status: $current->status,
            contactId: $contactId,
            contactName: $change->contactId !== null && $change->contactId !== $current->contactId
                ? $this->contactNameFor($change->contactId)
                : $current->contactName,
            bankAccountId: $current->bankAccountId,
            bankAccountName: $current->bankAccountName,
            reference: $current->reference,
            isReconciled: $current->isReconciled,
            hasAttachments: $current->hasAttachments,
            lines: $lines,
            updatedDateUtc: new DateTimeImmutable,
            lineAmountType: $current->lineAmountType,
            currencyRate: $current->currencyRate,
        );
    }

    private function contactNameFor(string $contactId): ?string
    {
        foreach ($this->knownContacts as $contact) {
            if ($contact->id === $contactId) {
                return $contact->name;
            }
        }

        return null;
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
     * @return array<int, array{type: EntityType, payload: EntityPayload, connection: Connection, idempotency_key: string|null, direction: MoneyDirection|null}>
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
        $this->resolvedContactIds = [];
        $this->unsupported = [];
        $this->bankTransactions = [];
        $this->bankTransactionQueries = [];
        $this->recodings = [];
        $this->changes = [];
        $this->deleted = [];
        $this->contactLookups = [];
        $this->knownContacts = [];
        $this->nextRecodeResult = null;
        $this->mutateBeforeNextRecode = null;
        $this->nextBankTransactionFailure = null;
        $this->nextRecodingFailure = null;
        $this->afterNextBankTransactionList = null;
    }
}
