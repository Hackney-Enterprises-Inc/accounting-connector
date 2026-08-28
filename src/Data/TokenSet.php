<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;

/**
 * A set of OAuth2 tokens as the provider just issued them.
 *
 * Held only long enough to be written onto a Connection and handed to the host's
 * ConnectionStore. Nothing in this package persists or encrypts it.
 */
final readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $refreshTokenExpiresAt = null,
    ) {}

    /**
     * Build from a provider token response.
     *
     * Both Xero and Intuit return `expires_in` as a relative number of seconds.
     * Intuit additionally returns `x_refresh_token_expires_in`, which is how a
     * host can warn a customer before a dormant connection lapses.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable;

        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : null;
        $refreshExpiresIn = isset($payload['x_refresh_token_expires_in'])
            ? (int) $payload['x_refresh_token_expires_in']
            : null;

        return new self(
            accessToken: (string) ($payload['access_token'] ?? ''),
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            expiresAt: $expiresIn !== null ? $now->modify("+{$expiresIn} seconds") : null,
            refreshTokenExpiresAt: $refreshExpiresIn !== null ? $now->modify("+{$refreshExpiresIn} seconds") : null,
        );
    }

    /**
     * The shape a host typically persists, with timestamps as unix integers.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: int|null, refresh_token_expires_at: int|null}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt?->getTimestamp(),
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt?->getTimestamp(),
        ];
    }
}
