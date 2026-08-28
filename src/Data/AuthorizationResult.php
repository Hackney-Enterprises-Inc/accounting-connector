<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\AuthenticationException;

/**
 * What comes back from an OAuth callback: tokens, plus which company they are for.
 *
 * The two providers deliver the company differently. Intuit puts the realm id in
 * the callback query string. Xero does not send one at all, so the connector spends
 * a second request on GET /connections to find out, and a Xero user with access to
 * several organizations can authorise more than one at a time. `$tenants` carries
 * all of them so a host can ask the customer which to use; `$tenantId` is the first,
 * which is the right default for the overwhelmingly common single-tenant case.
 */
final readonly class AuthorizationResult
{
    /**
     * @param  array<int, TenantInfo>  $tenants
     */
    public function __construct(
        public Provider $provider,
        public TokenSet $tokens,
        public ?string $tenantId = null,
        public array $tenants = [],
    ) {}

    /**
     * Turn the result into a usable connection.
     *
     * @param  array<string, mixed>  $settings
     */
    public function toConnection(?string $tenantId = null, array $settings = [], ?string $reference = null): Connection
    {
        $tenantId ??= $this->tenantId;

        if ($tenantId === null || $tenantId === '') {
            throw new AuthenticationException(
                sprintf(
                    'Authorisation succeeded but no %s %s came back, so there is no company to connect to.',
                    $this->provider->label(),
                    $this->provider->tenantLabel(),
                ),
                $this->provider,
            );
        }

        return new Connection(
            provider: $this->provider,
            tenantId: $tenantId,
            accessToken: $this->tokens->accessToken,
            refreshToken: $this->tokens->refreshToken,
            expiresAt: $this->tokens->expiresAt,
            refreshTokenExpiresAt: $this->tokens->refreshTokenExpiresAt,
            settings: $settings,
            reference: $reference,
        );
    }

    /**
     * Whether the customer authorised more than one company and must pick.
     */
    public function needsTenantSelection(): bool
    {
        return count($this->tenants) > 1;
    }
}
