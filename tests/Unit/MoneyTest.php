<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

it('converts integer cents to the decimal the provider APIs expect', function () {
    expect(Money::cents(12500)->toDecimal())->toBe(125.0)
        ->and(Money::cents(1)->toDecimal())->toBe(0.01)
        ->and(Money::cents(-4599)->toDecimal())->toBe(-45.99);
});

it('rounds a decimal to the nearest cent', function () {
    expect(Money::fromDecimal(19.999)->amount)->toBe(2000)
        ->and(Money::fromDecimal('12.345')->amount)->toBe(1235)
        ->and(Money::fromDecimal(0.005)->amount)->toBe(1);
});

it('refuses a non-numeric amount', function () {
    Money::fromDecimal('not money');
})->throws(InvalidPayloadException::class);

it('multiplies without drifting off a cent', function () {
    // 3 x 33.33 is exactly 99.99, and must not come back as 99.98999999999999.
    expect(Money::cents(3333)->times(3)->amount)->toBe(9999)
        ->and(Money::cents(3333)->times(3)->toDecimal())->toBe(99.99);
});

it('sums a set of lines exactly', function () {
    $total = Money::zero()
        ->plus(Money::cents(1050))
        ->plus(Money::cents(2599))
        ->plus(Money::cents(1));

    expect($total->amount)->toBe(3650);
});

it('knows a negative amount from a zero one', function () {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::cents(-1)->isNegative())->toBeTrue()
        ->and(Money::cents(1)->isNegative())->toBeFalse()
        ->and(Money::cents(500)->negated()->amount)->toBe(-500);
});
