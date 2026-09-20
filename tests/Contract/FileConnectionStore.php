<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Tests\Contract;

use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Data\Connection;
use RuntimeException;

/**
 * Keeps the demo company's tokens in a file, so a refresh survives the run.
 *
 * Xero rotates the refresh token on every refresh: the old one stops working the
 * moment the new one is issued. The connector persists through this store before it
 * uses a refreshed token (AbstractConnector::refresh), so a run that refreshes and
 * then dies still leaves the file holding the token Xero now considers current. A
 * suite that kept tokens in memory would brick the next run every time the access
 * token happened to be stale.
 *
 * Written atomically (temp file plus rename) with owner-only permissions.
 */
final class FileConnectionStore implements ConnectionStore
{
    public function __construct(private readonly string $path) {}

    public function persist(Connection $connection): void
    {
        $payload = json_encode([
            'access_token' => $connection->accessToken,
            'refresh_token' => $connection->refreshToken,
            'expires_at' => $connection->expiresAt?->getTimestamp(),
            'refresh_token_expires_at' => $connection->refreshTokenExpiresAt?->getTimestamp(),
            'tenant_id' => $connection->tenantId,
            'rotated_at' => time(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporary, $payload."\n") === false) {
            throw new RuntimeException("Could not write the rotated tokens to {$temporary}.");
        }

        chmod($temporary, 0600);

        if (! rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException("Could not move the rotated tokens into place at {$this->path}.");
        }
    }

    public function path(): string
    {
        return $this->path;
    }
}
