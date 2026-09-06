<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * One page of bank transactions, and whether there is another.
 *
 * A bare array would make the caller responsible for knowing the provider's page
 * size to decide whether to ask again, which is exactly the provider detail this
 * package exists to keep out of hosts.
 */
final readonly class BankTransactionPage
{
    /**
     * @param  array<int, BankTransactionData>  $transactions
     */
    public function __construct(
        public array $transactions,
        public int $page,
        public int $pageSize = BankTransactionQuery::PAGE_SIZE,
    ) {}

    /**
     * Whether asking for the next page could return anything.
     *
     * A full page is the only signal Xero gives: it carries no total and no cursor,
     * so a short page is the end and a full one might not be.
     */
    public function hasMore(): bool
    {
        return count($this->transactions) >= $this->pageSize;
    }

    public function isEmpty(): bool
    {
        return $this->transactions === [];
    }

    public function count(): int
    {
        return count($this->transactions);
    }
}
