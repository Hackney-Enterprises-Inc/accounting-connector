<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * Which bank transactions a host wants back.
 *
 * Every field narrows the request at the provider rather than in the host, which
 * matters more here than it does for a lookup: a company with three years of bank
 * feed history holds tens of thousands of these, and Xero allows this app sixty
 * calls a minute and five thousand a day against each connected organisation.
 *
 * `$modifiedSince` is the one that makes a repeating sync affordable. It becomes an
 * If-Modified-Since header rather than part of the filter, and it is compared
 * against UpdatedDateUTC, so a nightly refresh asks only for what has changed.
 *
 * `$order` and `$pageSize` are what make a full walk of the history affordable and
 * resumable. Xero orders by `UpdatedDateUTC ASC, BankTransactionID ASC` when nothing
 * is asked for; a host walking date slices asks for `Date ASC` so a page boundary
 * means the same thing on the next run.
 */
final readonly class BankTransactionQuery
{
    /**
     * Xero's own page size when none is requested.
     *
     * Kept for hosts that size fixtures by it; a query sends {@see self::DEFAULT_PAGE_SIZE}
     * unless told otherwise.
     */
    public const PAGE_SIZE = 100;

    /**
     * What a query asks for when the host does not say.
     *
     * Xero's documentation page for the endpoint says 250; the OpenAPI spec says
     * 1000. Until a contract test against a live organisation records the accepted
     * maximum, the documented figure is the default. Raise it through configuration
     * once the test has run.
     */
    public const DEFAULT_PAGE_SIZE = 250;

    /**
     * The most the spec allows. Anything above it is refused here rather than
     * silently clamped by Xero, so a misconfiguration is visible.
     */
    public const MAX_PAGE_SIZE = 1000;

    public function __construct(
        /** Null takes every type. A mirror wants everything; a matcher wants SPEND or RECEIVE only. */
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
        /**
         * A provider-native order expression such as `Date ASC` or `UpdatedDateUTC DESC`.
         * Null leaves the provider's default order in place.
         */
        public ?string $order = null,
        /** Rows per page, 1 to {@see self::MAX_PAGE_SIZE}. Null means the connector's configured default. */
        public ?int $pageSize = null,
    ) {
        if ($this->page < 1) {
            throw new InvalidPayloadException("A bank transaction query pages from 1, not {$this->page}.");
        }

        if ($this->pageSize !== null && ($this->pageSize < 1 || $this->pageSize > self::MAX_PAGE_SIZE)) {
            throw new InvalidPayloadException(sprintf(
                'A bank transaction page size must be between 1 and %d, got %d.',
                self::MAX_PAGE_SIZE,
                $this->pageSize,
            ));
        }

        // A field name, optionally followed by a direction. Anything else is not an
        // order expression either provider understands, and letting it through
        // would put an unparseable query on the wire.
        if ($this->order !== null && preg_match('/^[A-Za-z][A-Za-z0-9.]*(?: (?:ASC|DESC))?$/', $this->order) !== 1) {
            throw new InvalidPayloadException("Not a bank transaction order expression: {$this->order}");
        }
    }

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
            order: $this->order,
            pageSize: $this->pageSize,
        );
    }

    /**
     * The page size this query will actually be sent with.
     */
    public function effectivePageSize(int $default = self::DEFAULT_PAGE_SIZE): int
    {
        return $this->pageSize ?? $default;
    }
}
