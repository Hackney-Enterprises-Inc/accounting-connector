<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

/**
 * The provider has no such entity (404), or reports the id as unknown.
 */
final class NotFoundException extends AccountingConnectorException {}
