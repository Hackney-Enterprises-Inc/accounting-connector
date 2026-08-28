<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

use Hei\AccountingConnector\Data\Connection;

/**
 * Where refreshed tokens go.
 *
 * This is not optional bookkeeping. Both providers expire access tokens in about
 * half an hour, and Intuit rotates the refresh token on every single refresh. A
 * host that does not persist what it is handed here keeps presenting a refresh
 * token that Intuit has already retired, and the connection dies at the next
 * refresh with no obvious cause.
 *
 * The package hands over plaintext tokens and never encrypts anything. Encryption
 * at rest is the host's job, because the host owns the key: in both Laravel apps
 * that is an `encrypted:array` cast on the connection model.
 */
interface ConnectionStore
{
    /**
     * Persist the connection's current tokens.
     *
     * Called immediately after a successful refresh, before the new access token
     * is used for anything.
     */
    public function persist(Connection $connection): void;
}
