<?php

declare(strict_types=1);

namespace Hei\AccountingConnector;

use Hei\AccountingConnector\Contracts\AccountingConnector;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\AccountingConnectorException;

/**
 * Picks the connector for a provider.
 *
 * Registration is by closure so a connector is only built when something actually
 * needs it. Both applications will register Xero and QuickBooks and use whichever a
 * given customer connected, and constructing an unused one on every request is
 * waste that adds up in a queue worker.
 */
final class ConnectorManager
{
    /** @var array<string, callable(): AccountingConnector> */
    private array $factories = [];

    /** @var array<string, AccountingConnector> */
    private array $resolved = [];

    /**
     * @param  callable(): AccountingConnector  $factory
     */
    public function register(Provider $provider, callable $factory): self
    {
        $this->factories[$provider->value] = $factory;
        unset($this->resolved[$provider->value]);

        return $this;
    }

    /**
     * Register an already-built connector, for tests and fakes.
     */
    public function set(Provider $provider, AccountingConnector $connector): self
    {
        $this->resolved[$provider->value] = $connector;
        $this->factories[$provider->value] = fn (): AccountingConnector => $connector;

        return $this;
    }

    public function has(Provider $provider): bool
    {
        return isset($this->factories[$provider->value]);
    }

    /**
     * @return array<int, Provider>
     */
    public function registered(): array
    {
        return array_map(
            fn (string $value): Provider => Provider::from($value),
            array_keys($this->factories),
        );
    }

    public function for(Provider $provider): AccountingConnector
    {
        if (isset($this->resolved[$provider->value])) {
            return $this->resolved[$provider->value];
        }

        if (! isset($this->factories[$provider->value])) {
            throw new AccountingConnectorException(sprintf(
                'No connector is registered for %s. Configure its client id and secret, '
                .'or register one on the ConnectorManager.',
                $provider->label(),
            ), $provider);
        }

        return $this->resolved[$provider->value] = ($this->factories[$provider->value])();
    }

    /**
     * The connector this connection belongs to.
     */
    public function forConnection(Connection $connection): AccountingConnector
    {
        return $this->for($connection->provider);
    }
}
