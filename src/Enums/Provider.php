<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

/**
 * The accounting systems this package can talk to.
 *
 * The backing value is what a host application persists on its own connection
 * row, so it is part of the package's public contract and must not change.
 */
enum Provider: string
{
    case Xero = 'xero';
    case QuickBooksOnline = 'quickbooks_online';

    /**
     * Human-readable name, safe to show a customer.
     */
    public function label(): string
    {
        return match ($this) {
            self::Xero => 'Xero',
            self::QuickBooksOnline => 'QuickBooks Online',
        };
    }

    /**
     * What the provider calls the identifier we store as Connection::$tenantId.
     *
     * Xero calls it a tenant id, Intuit calls it a realm id. Used for log and
     * error messages so operators can match our value to the vendor's console.
     */
    public function tenantLabel(): string
    {
        return match ($this) {
            self::Xero => 'tenant id',
            self::QuickBooksOnline => 'realm id',
        };
    }
}
