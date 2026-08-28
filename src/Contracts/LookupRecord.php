<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Contracts;

/**
 * A lookup result that can be stored and rebuilt.
 *
 * Chart-of-accounts, tax-code and tracking-category lists all get cached and, when a
 * LookupStore is bound, persisted. Both round trips go through plain arrays, so the
 * types that take part say how to flatten themselves.
 */
interface LookupRecord
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
