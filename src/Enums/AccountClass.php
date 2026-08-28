<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Enums;

/**
 * What an account is for, normalised across providers.
 *
 * The two vendors describe the same account in incompatible vocabularies. Xero says
 * EXPENSE, DIRECTCOSTS and OVERHEADS; QuickBooks says Expense, Other Expense and
 * Cost of Goods Sold. A host building a "default expense account" dropdown should
 * not have to know either list, so both are folded into this.
 *
 * The provider's own string is still on Account::$type when something genuinely
 * needs it.
 */
enum AccountClass: string
{
    case Expense = 'expense';
    case Revenue = 'revenue';
    case Bank = 'bank';
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Other = 'other';

    /**
     * Classify a Xero account type.
     */
    public static function fromXero(?string $type): self
    {
        return match (strtoupper($type ?? '')) {
            'BANK' => self::Bank,
            'EXPENSE', 'DIRECTCOSTS', 'OVERHEADS', 'DEPRECIATN' => self::Expense,
            'REVENUE', 'SALES', 'OTHERINCOME' => self::Revenue,
            'CURRENT', 'FIXED', 'INVENTORY', 'NONCURRENT', 'PREPAYMENT' => self::Asset,
            // The OpenAPI spec says PAYG; production tenants have also returned the
            // longer PAYGLIABILITY et al. Both are liabilities, so both are kept.
            'CURRLIAB', 'LIABILITY', 'TERMLIAB', 'PAYG', 'PAYGLIABILITY',
            'SUPERANNUATIONLIABILITY', 'WAGESPAYABLELIABILITY' => self::Liability,
            'EQUITY' => self::Equity,
            default => self::Other,
        };
    }

    /**
     * Classify a QuickBooks account type.
     *
     * Credit Card is deliberately classed as Bank rather than Liability. It is a
     * liability on the balance sheet, but every question this enum gets asked about
     * it is "can money be spent from here", and the answer is yes. AccountingPipe's
     * own payment-account lookup makes the same call.
     */
    public static function fromQuickBooks(?string $type): self
    {
        return match ($type ?? '') {
            'Bank', 'Credit Card' => self::Bank,
            'Expense', 'Other Expense', 'Cost of Goods Sold' => self::Expense,
            'Income', 'Other Income' => self::Revenue,
            'Accounts Receivable', 'Fixed Asset', 'Other Asset', 'Other Current Asset' => self::Asset,
            'Accounts Payable', 'Long Term Liability', 'Other Current Liability' => self::Liability,
            'Equity' => self::Equity,
            default => self::Other,
        };
    }

    /**
     * Whether a line item on a money-out transaction can be coded here.
     */
    public function isSpendable(): bool
    {
        return $this === self::Expense;
    }
}
