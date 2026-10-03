<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\TenantInfo;

it('still builds from the original positional arguments, with no lock dates', function () {
    $info = new TenantInfo('t-1', 'Acme Ltd', 'Acme Limited', 'GB', 'GBP');

    expect($info->legalName)->toBe('Acme Limited')
        ->and($info->currencyCode)->toBe('GBP')
        ->and($info->periodLockDate)->toBeNull()
        ->and($info->endOfYearLockDate)->toBeNull();
});

it('lists the lock dates as plain dates in its array form', function () {
    $info = new TenantInfo(
        id: 't-1',
        name: 'Acme Ltd',
        periodLockDate: new DateTimeImmutable('2026-06-30', new DateTimeZone('UTC')),
    );

    expect($info->toArray())->toBe([
        'id' => 't-1',
        'name' => 'Acme Ltd',
        'legal_name' => null,
        'country_code' => null,
        'currency_code' => null,
        'period_lock_date' => '2026-06-30',
        'end_of_year_lock_date' => null,
    ]);
});
