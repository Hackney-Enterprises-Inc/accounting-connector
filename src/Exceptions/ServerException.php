<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

/**
 * The provider failed on its own side (5xx) and outlasted our retries, or
 * answered with a value the connector cannot read where guessing would be unsafe
 * (an unreadable lock date, which must not be taken to mean "no lock").
 *
 * Retryable later. Note that a 5xx on a create is genuinely ambiguous: the entity
 * may or may not exist. Always pass an idempotency key on creates so the retry
 * cannot double-post.
 */
final class ServerException extends AccountingConnectorException {}
