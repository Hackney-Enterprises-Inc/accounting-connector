<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * One page of bank transactions, and whether there is another.
 *
 * A bare array would make the caller responsible for knowing the provider's page
 * size to decide whether to ask again, which is exactly the provider detail this
 * package exists to keep out of hosts.
 *
 * Xero's paged responses carry a `pagination` object with the total item and page
 * counts. When it is present it is authoritative and lets a host prove a walk was
 * complete: the number of distinct rows it mirrored either equals `itemCount` or
 * something moved underneath it. When it is absent (an older fixture, a 304) the
 * full-page heuristic stands in.
 */
final readonly class BankTransactionPage
{
    /**
     * @param  array<int, BankTransactionData>  $transactions
     */
    public function __construct(
        public array $transactions,
        public int $page,
        public int $pageSize = BankTransactionQuery::DEFAULT_PAGE_SIZE,
        /** Every row matching the query across all pages, when the provider says. */
        public ?int $itemCount = null,
        /** How many pages the query spans, when the provider says. */
        public ?int $pageCount = null,
    ) {}

    /**
     * Whether asking for the next page could return anything.
     *
     * The provider's page count when it gave one; otherwise a full page is the only
     * signal, so a short page is the end and a full one might not be.
     */
    public function hasMore(): bool
    {
        if ($this->pageCount !== null) {
            return $this->page < $this->pageCount;
        }

        return count($this->transactions) >= $this->pageSize;
    }

    /**
     * Whether the provider told us how many rows the whole query holds.
     */
    public function isCounted(): bool
    {
        return $this->itemCount !== null;
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
