<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

/**
 * The caller handed us something we cannot turn into a valid provider payload.
 *
 * Thrown before any HTTP call, so nothing was posted.
 */
final class InvalidPayloadException extends AccountingConnectorException {}
