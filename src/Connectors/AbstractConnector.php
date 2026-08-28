<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Connectors;

use Hei\AccountingConnector\Contracts\AccountingConnector;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Contracts\EntityMap;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Contracts\LookupRecord;
use Hei\AccountingConnector\Contracts\LookupStore;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\AttachmentSet;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Events\AttachmentUploaded;
use Hei\AccountingConnector\Events\ConnectionRevoked;
use Hei\AccountingConnector\Events\EntityCreated;
use Hei\AccountingConnector\Events\EntityCreateFailed;
use Hei\AccountingConnector\Events\SyncEvent;
use Hei\AccountingConnector\Events\TokensRefreshed;
use Hei\AccountingConnector\Exceptions\AccountingConnectorException;
use Hei\AccountingConnector\Exceptions\AuthenticationException;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\NotFoundException;
use Hei\AccountingConnector\Exceptions\RateLimitException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Exceptions\UnsupportedEntityTypeException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Http\HttpClient;
use Hei\AccountingConnector\Http\HttpResponse;
use Hei\AccountingConnector\Support\IdempotencyKey;
use Hei\AccountingConnector\Support\NullConnectionStore;
use Hei\AccountingConnector\Support\NullEntityMap;
use Hei\AccountingConnector\Support\NullLookupStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Everything the two connectors do identically.
 *
 * Token freshness, event emission, lookup caching, entity-map bookkeeping and the
 * attachment contract are the same regardless of which vendor is on the other end.
 * What differs is the wire format, and that lives in the subclasses.
 */
abstract class AbstractConnector implements AccountingConnector
{
    public function __construct(
        protected readonly HttpClient $http,
        protected readonly string $clientId,
        protected readonly string $clientSecret,
        protected readonly string $redirectUri,
        protected readonly ConnectionStore $connections = new NullConnectionStore,
        protected readonly EntityMap $entityMap = new NullEntityMap,
        protected readonly LookupStore $lookups = new NullLookupStore,
        protected readonly ?CacheInterface $cache = null,
        protected readonly ?EventDispatcherInterface $events = null,
        protected readonly LoggerInterface $logger = new NullLogger,
        /** How long chart-of-accounts and tax-code lookups stay cached. */
        protected readonly int $lookupTtl = 3600,
    ) {}

    /**
     * The most bytes this provider will accept on one attachment.
     */
    abstract public function attachmentSizeLimit(): int;

    /**
     * Post a prepared payload. Called with a connection already known to be fresh.
     */
    abstract protected function performCreate(
        EntityType $type,
        EntityPayload $payload,
        Connection $connection,
        ?string $idempotencyKey,
    ): ?string;

    abstract protected function performUpdate(
        EntityType $type,
        string $externalId,
        EntityPayload $payload,
        Connection $connection,
    ): bool;

    abstract protected function performAttach(
        EntityType $type,
        string $externalId,
        Attachment $attachment,
        Connection $connection,
    ): AttachmentResult;

    public function createEntity(
        EntityType $type,
        EntityPayload $payload,
        Connection $connection,
        ?string $idempotencyKey = null,
    ): ?string {
        $this->assertSupported($type);
        $this->assertPayload($type, $payload);

        $connection = $this->fresh($connection);

        if ($idempotencyKey !== null) {
            $idempotencyKey = IdempotencyKey::truncate($idempotencyKey, $this->provider());
        }

        try {
            $externalId = $this->performCreate($type, $payload, $connection, $idempotencyKey);
        } catch (AccountingConnectorException $e) {
            $this->dispatch(new EntityCreateFailed(
                connection: $connection,
                entityType: $type,
                reason: $e->getMessage(),
                retryable: $this->isRetryable($e),
                localId: $payload->localId(),
                exception: $e,
            ));

            throw $e;
        }

        if ($externalId === null) {
            $this->dispatch(new EntityCreateFailed(
                connection: $connection,
                entityType: $type,
                reason: sprintf('%s accepted the request but returned no id.', $this->provider()->label()),
                retryable: true,
                localId: $payload->localId(),
            ));

            return null;
        }

        $localId = $payload->localId();

        if ($localId !== null) {
            $this->entityMap->remember($connection, $type, $localId, $externalId);
        }

        $this->dispatch(new EntityCreated(
            connection: $connection,
            entityType: $type,
            externalId: $externalId,
            localId: $localId,
            idempotencyKey: $idempotencyKey,
        ));

        return $externalId;
    }

    public function updateEntity(
        EntityType $type,
        string $externalId,
        EntityPayload $payload,
        Connection $connection,
    ): bool {
        $this->assertSupported($type);
        $this->assertPayload($type, $payload);

        return $this->performUpdate($type, $externalId, $payload, $this->fresh($connection));
    }

    public function attach(
        EntityType $type,
        string $externalId,
        Attachment|AttachmentSet $attachment,
        Connection $connection,
    ): AttachmentResult {
        $set = AttachmentSet::wrap($attachment);
        $limit = $this->attachmentSizeLimit();

        $chosen = $set->firstUnder($limit);

        if ($chosen === null) {
            // Every rendering was too big. Report it rather than throwing: the
            // transaction this belongs to has already posted, and failing here would
            // make a retry create a second one.
            $result = AttachmentResult::tooLarge($set->smallest()->size(), $limit);
            $this->dispatch(new AttachmentUploaded($connection, $type, $externalId, $result));

            return $result;
        }

        try {
            $result = $this->performAttach($type, $externalId, $chosen, $this->fresh($connection));
        } catch (\Throwable $e) {
            // Deliberately catches Throwable, not just our own exceptions. Nothing an
            // attachment can do is worth undoing a posted transaction.
            $this->logger->error('Attachment upload failed after the entity was created.', [
                'provider' => $this->provider()->value,
                'entity_type' => $type->value,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            $result = AttachmentResult::failed($e->getMessage(), $chosen->normalisedFilename());
        }

        $this->dispatch(new AttachmentUploaded($connection, $type, $externalId, $result));

        return $result;
    }

    public function refresh(Connection $connection): Connection
    {
        if (! $connection->isRefreshable()) {
            throw ConnectionRevokedException::for($connection, 'No refresh token is stored.');
        }

        if ($connection->isRefreshExpired()) {
            $this->announceRevocation($connection, 'The refresh token expired.');

            throw ConnectionRevokedException::for($connection, 'The refresh token expired.');
        }

        $refreshed = $connection->withTokens($this->requestRefresh($connection));

        // Persist before use. If the process dies between here and the next request,
        // the stored tokens are the ones the provider now considers current, which
        // matters because Intuit has already retired the old refresh token.
        $this->connections->persist($refreshed);

        $this->dispatch(new TokensRefreshed($refreshed));

        return $refreshed;
    }

    /**
     * Exchange a refresh token for a new token set. Provider-specific.
     */
    abstract protected function requestRefresh(Connection $connection): TokenSet;

    /**
     * A connection with a usable access token, refreshing first if needed.
     */
    protected function fresh(Connection $connection): Connection
    {
        if (! $connection->isExpired()) {
            return $connection;
        }

        if (! $connection->isRefreshable()) {
            throw new AuthenticationException(
                sprintf(
                    'The %s access token has expired and there is no refresh token to renew it with.',
                    $this->provider()->label(),
                ),
                $this->provider(),
            );
        }

        return $this->refresh($connection);
    }

    protected function assertSupported(EntityType $type): void
    {
        if (! $this->supports($type)) {
            throw UnsupportedEntityTypeException::for($this->provider(), $type);
        }
    }

    /**
     * Refuse a payload that does not describe the entity being created.
     *
     * Catches the mistyped-argument class of bug before it becomes a bill posted as
     * an expense, which reconciles to the same total and is a nuisance to unpick.
     */
    protected function assertPayload(EntityType $type, EntityPayload $payload): void
    {
        if ($payload instanceof RawPayload) {
            $payload->assertMatches($this->provider());
        }

        if ($payload->entityType() !== $type) {
            throw new InvalidPayloadException(sprintf(
                'Cannot create a "%s": the payload describes a "%s".',
                $type->value,
                $payload->entityType()->value,
            ), $this->provider());
        }
    }

    /**
     * Narrow a payload to the concrete DTO the caller was supposed to pass.
     *
     * @template T of EntityPayload
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function as(EntityPayload $payload, string $class): EntityPayload
    {
        if (! $payload instanceof $class) {
            $expected = strrchr($class, '\\');

            throw new InvalidPayloadException(sprintf(
                'Expected a %s, got a %s.',
                $expected === false ? $class : substr($expected, 1),
                $payload::class,
            ), $this->provider());
        }

        return $payload;
    }

    /**
     * A lookup list, resolved through cache, then durable store, then the provider.
     *
     * The order matters and each layer earns its place:
     *
     *  - Cache first, because a settings page reads these on every load.
     *  - Durable store second, so a deploy that flushes the cache does not send
     *    every organization back to Xero against a shared 60-per-minute ceiling.
     *  - Provider last, and what it returns is written to both layers.
     *
     * If the provider is unreachable and anything was stored previously, the stored
     * list is served and a warning logged. A Xero outage should leave a settings
     * page slightly stale, not empty, because an empty account dropdown reads to a
     * customer as "my chart of accounts is gone". With nothing stored there is
     * nothing honest to show, so the error propagates.
     *
     * @template T of LookupRecord
     *
     * @param  callable(): array<int, T>  $fetch
     * @param  callable(array<string, mixed>): T  $hydrate
     * @return array<int, T>
     */
    protected function lookup(
        Connection $connection,
        string $key,
        bool $forceRefresh,
        callable $fetch,
        callable $hydrate,
    ): array {
        $cacheKey = $connection->cacheKey($key);

        if (! $forceRefresh) {
            $cached = $this->cache?->get($cacheKey);

            if (is_array($cached)) {
                return $this->hydrateAll($cached, $hydrate);
            }

            $stored = $this->lookups->get($connection, $key);

            if ($stored !== null) {
                $this->cache?->set($cacheKey, $stored, $this->lookupTtl);

                return $this->hydrateAll($stored, $hydrate);
            }
        }

        try {
            $records = $fetch();
        } catch (AccountingConnectorException $e) {
            $stale = $this->lookups->get($connection, $key);

            if ($stale === null) {
                throw $e;
            }

            $this->logger->warning('Serving a stored accounting lookup because the provider is unreachable.', [
                'provider' => $this->provider()->value,
                'lookup' => $key,
                'connection' => $connection->reference,
                'error' => $e->getMessage(),
            ]);

            return $this->hydrateAll($stale, $hydrate);
        }

        $flat = array_map(fn (LookupRecord $record): array => $record->toArray(), $records);

        $this->lookups->put($connection, $key, $flat);
        $this->cache?->set($cacheKey, $flat, $this->lookupTtl);

        return $records;
    }

    /**
     * Re-fetch every lookup this provider supports, discarding what is held.
     *
     * The "refresh accounts" button a host puts on its integrations page.
     */
    public function refreshLookups(Connection $connection): void
    {
        $this->chartOfAccounts($connection, forceRefresh: true);
        $this->taxCodes($connection, forceRefresh: true);
    }

    /**
     * @template T of LookupRecord
     *
     * @param  array<int, mixed>  $rows
     * @param  callable(array<string, mixed>): T  $hydrate
     * @return array<int, T>
     */
    private function hydrateAll(array $rows, callable $hydrate): array
    {
        $records = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $records[] = $hydrate($row);
            }
        }

        return $records;
    }

    protected function dispatch(SyncEvent $event): void
    {
        $this->events?->dispatch($event);
    }

    protected function announceRevocation(Connection $connection, string $reason): void
    {
        $this->dispatch(new ConnectionRevoked($connection, $reason));
    }

    /**
     * Whether running the same call again later could plausibly work.
     */
    protected function isRetryable(AccountingConnectorException $e): bool
    {
        return $e instanceof RateLimitException || $e instanceof ServerException;
    }

    /**
     * Turn a failed response into the right exception, in one place per provider.
     *
     * Subclasses supply the provider's own error wording through describeError().
     */
    protected function raise(HttpResponse $response, Connection $connection, string $action): never
    {
        $message = $this->describeError($response);
        $provider = $this->provider();

        throw match (true) {
            $response->status === 401 => $this->unauthorised($connection, $message),
            $response->status === 403 => new AuthenticationException(
                sprintf('%s refused %s: %s', $provider->label(), $action, $message),
                $provider,
                $message,
            ),
            $response->status === 404 => new NotFoundException(
                sprintf('%s has no such record for %s: %s', $provider->label(), $action, $message),
                $provider,
                $message,
            ),
            $response->status === 429 => new RateLimitException(
                sprintf('%s rate limited %s.', $provider->label(), $action),
                $provider,
                $response->retryAfter(),
                $message,
            ),
            $response->status >= 500 => new ServerException(
                sprintf('%s failed on its own side during %s: %s', $provider->label(), $action, $message),
                $provider,
                $message,
            ),
            default => new ValidationException(
                sprintf('%s rejected %s: %s', $provider->label(), $action, $message),
                $provider,
                $this->describeErrors($response),
                $message,
            ),
        };
    }

    /**
     * A 401 on a connection whose token we just refreshed means the grant is gone,
     * not that the token was stale. That needs a human, so it is escalated.
     */
    private function unauthorised(Connection $connection, string $message): AccountingConnectorException
    {
        $this->announceRevocation($connection, $message);

        return ConnectionRevokedException::for($connection, $message);
    }

    /**
     * The provider's own description of what went wrong.
     */
    abstract protected function describeError(HttpResponse $response): string;

    /**
     * Individual validation messages, when the provider itemises them.
     *
     * @return array<int, string>
     */
    abstract protected function describeErrors(HttpResponse $response): array;
}
