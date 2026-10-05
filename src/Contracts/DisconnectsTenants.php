<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\DisconnectOutcome;

/**
 * Removing a host's connection to one tenant at the provider, and saying what became
 * of it.
 *
 * Optional, like {@see VoidsInvoices}: a host asks with `instanceof`. It exists for a
 * host's Disconnect button. {@see AccountingConnector::revoke()} answers a bool, and
 * false covers both "the provider no longer has this connection" (the customer
 * removed the app at the provider first, or this is a second click), which is a
 * clean disconnect, and "the provider could not be reached or would not answer",
 * which leaves the app listed there and needs a person to remove it by hand. This
 * tells the two apart.
 *
 * Only the tenant's own connection is removed. A provider grant can cover several
 * tenants (one bookkeeper connecting several organisations), and the others are
 * left connected.
 */
interface DisconnectsTenants
{
    /**
     * Remove this connection's tenant at the provider. Never throws.
     *
     * {@see DisconnectOutcome::Removed} when it was removed now;
     * {@see DisconnectOutcome::NotConnected} when the provider was read and does not
     * hold it; {@see DisconnectOutcome::Unconfirmed} for anything else. A host clears
     * its own credentials whatever the answer: a provider failure must never leave a
     * customer unable to disconnect. That holds when the host's logger throws while
     * a failure is being logged: the answer is still Unconfirmed.
     */
    public function disconnectTenant(Connection $connection): DisconnectOutcome;
}
