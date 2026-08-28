<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Exceptions;

use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;

/**
 * This provider cannot represent the requested entity type.
 *
 * Check ConnectorInterface::supports() first if the calling code needs to branch
 * rather than fail.
 */
final class UnsupportedEntityTypeException extends AccountingConnectorException
{
    public static function for(Provider $provider, EntityType $type): self
    {
        return new self(
            sprintf('%s does not support the "%s" entity type.', $provider->label(), $type->value),
            $provider,
        );
    }
}
