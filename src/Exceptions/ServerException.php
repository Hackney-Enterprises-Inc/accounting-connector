<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

/**
 * The provider failed on its own side (5xx) and outlasted our retries.
 *
 * Retryable later. Note that a 5xx on a create is genuinely ambiguous: the entity
 * may or may not exist. Always pass an idempotency key on creates so the retry
 * cannot double-post.
 */
final class ServerException extends AccountingConnectorException {}
