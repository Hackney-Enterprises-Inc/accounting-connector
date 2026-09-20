<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Http;

use Hei\AccountingConnector\Enums\Provider;
use Throwable;

/**
 * A gate that is always open.
 *
 * The default when a host has not bound one, so the client behaves exactly as it
 * did before gates existed: every request goes out and the provider's own 429 is
 * the only brake.
 */
final class NullRequestGate implements RequestGate
{
    public function acquire(?Provider $provider, ?string $tenantId): void
    {
        // Nothing to spend, nothing to wait for.
    }

    public function release(?Provider $provider, ?string $tenantId, Throwable $failure): void
    {
        // Nothing was reserved.
    }
}
