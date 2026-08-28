<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

/**
 * Whether the line amounts already include tax.
 *
 * Getting this wrong does not fail: it silently posts a total that is off by the
 * tax amount, which is the kind of error a bookkeeper finds weeks later. Default
 * is Exclusive, matching both existing AccountingPipe mappers.
 */
enum LineAmountType: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';
    case NoTax = 'no_tax';

    public function toXero(): string
    {
        return match ($this) {
            self::Exclusive => 'Exclusive',
            self::Inclusive => 'Inclusive',
            self::NoTax => 'NoTax',
        };
    }

    /**
     * QuickBooks expresses this as a boolean GlobalTaxCalculation-adjacent flag on
     * the transaction rather than a named mode, and has no NoTax equivalent.
     */
    public function toQuickBooks(): string
    {
        return match ($this) {
            self::Inclusive => 'TaxInclusive',
            self::Exclusive => 'TaxExcluded',
            self::NoTax => 'NotApplicable',
        };
    }
}
