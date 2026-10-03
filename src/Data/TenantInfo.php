<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;

/**
 * Identifying details of the connected company.
 *
 * Shown back to the customer after connecting so they can confirm they linked the
 * right organization, which matters when a bookkeeper is signed in to several.
 *
 * The two lock dates are calendar dates at midnight UTC, or null when the company
 * has no such lock or the provider does not report one (QuickBooks never does
 * here). On Xero, postings dated on or before a lock date are refused:
 * periodLockDate binds every user except advisers, endOfYearLockDate binds every
 * user. Compare them by date (format('Y-m-d')), not as instants in a local zone.
 */
final readonly class TenantInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $legalName = null,
        public ?string $countryCode = null,
        public ?string $currencyCode = null,
        public ?DateTimeImmutable $periodLockDate = null,
        public ?DateTimeImmutable $endOfYearLockDate = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'country_code' => $this->countryCode,
            'currency_code' => $this->currencyCode,
            'period_lock_date' => $this->periodLockDate?->format('Y-m-d'),
            'end_of_year_lock_date' => $this->endOfYearLockDate?->format('Y-m-d'),
        ];
    }
}
