<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;
use Hei\AccountingConnector\Enums\BankTransactionType;

/**
 * Which bank transactions a host wants back.
 *
 * Every field narrows the request at the provider rather than in the host, which
 * matters more here than it does for a lookup: a company with three years of bank
 * feed history holds tens of thousands of these, and the per-tenant rate ceiling is
 * sixty calls a minute shared with every other app the customer has connected.
 *
 * `$modifiedSince` is the one that makes a repeating sync affordable. It becomes an
 * If-Modified-Since header rather than part of the filter, and it is compared
 * against UpdatedDateUTC, so a nightly refresh asks only for what has changed.
 */
final readonly class BankTransactionQuery
{
    /**
     * Xero returns at most this many transactions per page, and says so only by
     * handing back a full page. A caller that gets this many should ask for the next.
     */
    public const PAGE_SIZE = 100;

    public function __construct(
        /** Almost always Spend: a receipt evidences money that has left an account. */
        public ?BankTransactionType $type = BankTransactionType::Spend,
        /** Inclusive lower bound on the transaction date. */
        public ?DateTimeImmutable $from = null,
        /** Inclusive upper bound on the transaction date. */
        public ?DateTimeImmutable $to = null,
        /** The provider's id for the bank account, not its code. */
        public ?string $bankAccountId = null,
        /** Provider-native status, for example AUTHORISED. Null takes every status. */
        public ?string $status = null,
        /** Only transactions touched since this instant. Sent as a header, not a filter. */
        public ?DateTimeImmutable $modifiedSince = null,
        /** One-based. Xero pages from 1 and an out-of-range page is an empty list, not an error. */
        public int $page = 1,
    ) {}

    /**
     * The same query pointed at the next page.
     */
    public function nextPage(): self
    {
        return new self(
            type: $this->type,
            from: $this->from,
            to: $this->to,
            bankAccountId: $this->bankAccountId,
            status: $this->status,
            modifiedSince: $this->modifiedSince,
            page: $this->page + 1,
        );
    }
}
