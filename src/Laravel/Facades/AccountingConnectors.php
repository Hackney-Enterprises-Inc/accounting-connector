<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Laravel\Facades;

use Hei\AccountingConnector\ConnectorManager;
use Hei\AccountingConnector\Contracts\AccountingConnector;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;
use Illuminate\Support\Facades\Facade;

/**
 * @method static AccountingConnector for(Provider $provider)
 * @method static AccountingConnector forConnection(Connection $connection)
 * @method static bool has(Provider $provider)
 * @method static array<int, Provider> registered()
 * @method static ConnectorManager register(Provider $provider, callable $factory)
 * @method static ConnectorManager set(Provider $provider, AccountingConnector $connector)
 *
 * @see ConnectorManager
 */
final class AccountingConnectors extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConnectorManager::class;
    }
}
