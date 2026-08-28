<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Events;

/**
 * An access token was renewed.
 *
 * The connection on this event is the refreshed one, and it has already been handed
 * to the bound ConnectionStore. This event is for observability, not persistence:
 * a host that persists from here instead of implementing ConnectionStore will save
 * the tokens after they have already been used, which works right up until the
 * listener throws.
 */
final class TokensRefreshed extends SyncEvent
{
    public function name(): string
    {
        return 'tokens.refreshed';
    }

    public function context(): array
    {
        return parent::context() + [
            'expires_at' => $this->connection->expiresAt?->format(DATE_ATOM),
            'refresh_token_expires_at' => $this->connection->refreshTokenExpiresAt?->format(DATE_ATOM),
        ];
    }
}
