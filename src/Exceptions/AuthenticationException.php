<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

/**
 * The provider rejected our credentials (401), or we never had usable ones.
 *
 * A retry with the same connection will fail the same way. If the refresh token
 * is what expired, ConnectionRevokedException is thrown instead, because that one
 * needs a human to reconnect.
 */
final class AuthenticationException extends AccountingConnectorException {}
