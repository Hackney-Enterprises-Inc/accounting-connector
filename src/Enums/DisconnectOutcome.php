<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

use Hei\AccountingConnector\Contracts\DisconnectsTenants;

/**
 * What {@see DisconnectsTenants::disconnectTenant()} found at the provider.
 */
enum DisconnectOutcome: string
{
    /** The provider held the tenant's connection and removed it now. */
    case Removed = 'removed';

    /** The provider was read and holds no connection for the tenant: already gone. */
    case NotConnected = 'not_connected';

    /**
     * The provider could not be asked or did not answer plainly (an authentication
     * refused, a request failed, a reply that could not be read). The connection may
     * still be listed there.
     */
    case Unconfirmed = 'unconfirmed';

    /**
     * Whether the provider is known to hold no connection for the tenant now, so
     * nothing is left for a person to remove there.
     */
    public function isSettled(): bool
    {
        return $this !== self::Unconfirmed;
    }
}
