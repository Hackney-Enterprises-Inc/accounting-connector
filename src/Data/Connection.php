<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;
use Hei\AccountingConnector\Enums\Provider;

/**
 * One customer's live link to one accounting system.
 *
 * This is the framework-free stand-in for whatever organization or company model
 * the host application has. It carries no identity of its own beyond `$reference`,
 * which exists only so log lines and exception messages can name something the
 * operator recognises. Nothing here is company-shaped on purpose: the package must
 * work for an app whose tenant is an Organization and for an app whose tenant is a
 * Company without knowing the difference.
 *
 * Tokens arrive already decrypted. The host decrypts on the way in and encrypts on
 * the way out, because the host owns the key.
 *
 * Immutable: a refresh produces a new instance rather than mutating this one, so a
 * half-refreshed connection can never be observed.
 */
final readonly class Connection
{
    /**
     * @param  string  $tenantId  Xero tenant id, or Intuit realm id.
     * @param  array<string, mixed>  $settings  Per-connection defaults: expense account, bank account, tax code.
     * @param  string|null  $reference  The host's own id for this connection, for logs only.
     */
    public function __construct(
        public Provider $provider,
        public string $tenantId,
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $refreshTokenExpiresAt = null,
        public array $settings = [],
        public ?string $reference = null,
    ) {}

    /**
     * Whether the access token needs refreshing before the next call.
     *
     * The leeway matters: a token with four seconds left passes a naive expiry
     * check and then 401s mid-request. Treating the last minute as already expired
     * turns a race into a refresh.
     */
    public function isExpired(int $leewaySeconds = 60, ?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return true;
        }

        $now ??= new DateTimeImmutable;

        return $this->expiresAt->getTimestamp() - $leewaySeconds <= $now->getTimestamp();
    }

    /**
     * Whether the refresh token has lapsed, which no amount of retrying fixes.
     */
    public function isRefreshExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->refreshTokenExpiresAt === null) {
            return false;
        }

        return $this->refreshTokenExpiresAt->getTimestamp() <= ($now ?? new DateTimeImmutable)->getTimestamp();
    }

    /**
     * Whether there is anything to refresh with.
     */
    public function isRefreshable(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    /**
     * A copy carrying newly issued tokens.
     *
     * Intuit rotates the refresh token on every refresh but Xero does not always
     * return one, so an absent refresh token in the new set keeps the old one
     * rather than blanking it.
     */
    public function withTokens(TokenSet $tokens): self
    {
        return new self(
            provider: $this->provider,
            tenantId: $this->tenantId,
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken ?? $this->refreshToken,
            expiresAt: $tokens->expiresAt,
            refreshTokenExpiresAt: $tokens->refreshTokenExpiresAt ?? $this->refreshTokenExpiresAt,
            settings: $this->settings,
            reference: $this->reference,
        );
    }

    /**
     * A copy with different per-connection settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function withSettings(array $settings): self
    {
        return new self(
            provider: $this->provider,
            tenantId: $this->tenantId,
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken,
            expiresAt: $this->expiresAt,
            refreshTokenExpiresAt: $this->refreshTokenExpiresAt,
            settings: $settings,
            reference: $this->reference,
        );
    }

    /**
     * A per-connection setting, such as the default expense account.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * A stable, non-secret handle for cache keys and log lines.
     *
     * Never includes a token. The tenant id is hashed so a cache key cannot leak a
     * customer's Xero organization id into a shared cache namespace.
     */
    public function cacheKey(string $suffix): string
    {
        return sprintf(
            'accounting_connector.%s.%s.%s',
            $this->provider->value,
            substr(hash('sha256', $this->tenantId), 0, 16),
            $suffix,
        );
    }

    /**
     * Rehydrate from a host's stored array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $provider = $data['provider'] instanceof Provider
            ? $data['provider']
            : Provider::from((string) $data['provider']);

        return new self(
            provider: $provider,
            tenantId: (string) $data['tenant_id'],
            accessToken: (string) $data['access_token'],
            refreshToken: isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            expiresAt: self::toDate($data['expires_at'] ?? null),
            refreshTokenExpiresAt: self::toDate($data['refresh_token_expires_at'] ?? null),
            settings: is_array($data['settings'] ?? null) ? $data['settings'] : [],
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
        );
    }

    /**
     * The stored shape. Tokens are omitted unless explicitly asked for, so that a
     * connection dropped into a log context cannot leak credentials by accident.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeTokens = false): array
    {
        $data = [
            'provider' => $this->provider->value,
            'tenant_id' => $this->tenantId,
            'expires_at' => $this->expiresAt?->getTimestamp(),
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt?->getTimestamp(),
            'settings' => $this->settings,
            'reference' => $this->reference,
        ];

        if ($includeTokens) {
            $data['access_token'] = $this->accessToken;
            $data['refresh_token'] = $this->refreshToken;
        }

        return $data;
    }

    private static function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || ctype_digit((string) $value)) {
            return (new DateTimeImmutable)->setTimestamp((int) $value);
        }

        return new DateTimeImmutable((string) $value);
    }
}
