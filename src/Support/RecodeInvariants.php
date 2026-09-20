<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionLine;

/**
 * The one thing a recode must never do, checked the same way by the real
 * connector and by the fake: move money.
 *
 * A recode changes account codes, tracking and at most the contact. If the
 * provider's answer differs from the read in any amount, quantity or rate, the
 * write did something it was not asked to, and a host has to hear about it as a
 * distinct failure rather than as a successful recode.
 */
final class RecodeInvariants
{
    /**
     * Every amount that moved between the read and the provider's answer, in words.
     *
     * Empty means nothing moved. Header figures are compared when both sides carry
     * them; `Total` always does. Lines are matched by id, and a line that vanished
     * or appeared is a difference too, because Xero deletes and recreates a line it
     * was not given an id for.
     *
     * @return array<int, string>
     */
    public static function movedMoney(BankTransactionData $before, BankTransactionData $after): array
    {
        $moved = [];

        if ($before->total->amount !== $after->total->amount) {
            $moved[] = self::describe('Total', $before->total->amount, $after->total->amount);
        }

        if ($before->subTotal !== null && $after->subTotal !== null && $before->subTotal->amount !== $after->subTotal->amount) {
            $moved[] = self::describe('SubTotal', $before->subTotal->amount, $after->subTotal->amount);
        }

        if ($before->totalTax !== null && $after->totalTax !== null && $before->totalTax->amount !== $after->totalTax->amount) {
            $moved[] = self::describe('TotalTax', $before->totalTax->amount, $after->totalTax->amount);
        }

        if ($before->currencyRate !== null
            && $after->currencyRate !== null
            && abs($before->currencyRate - $after->currencyRate) > 0.000001) {
            $moved[] = sprintf('CurrencyRate %s became %s', $before->currencyRate, $after->currencyRate);
        }

        $beforeLines = self::linesById($before);
        $afterLines = self::linesById($after);

        foreach ($beforeLines as $id => $line) {
            if (! isset($afterLines[$id])) {
                $moved[] = "line {$id} is gone";

                continue;
            }

            $other = $afterLines[$id];

            if ($line->lineAmount !== null && $other->lineAmount !== null && $line->lineAmount->amount !== $other->lineAmount->amount) {
                $moved[] = self::describe("line {$id} LineAmount", $line->lineAmount->amount, $other->lineAmount->amount);
            }

            if ($line->quantity !== null && $other->quantity !== null && abs($line->quantity - $other->quantity) > 0.000001) {
                $moved[] = sprintf('line %s Quantity %s became %s', $id, $line->quantity, $other->quantity);
            }

            // Tax and the exact unit price too: two lines whose taxes went from
            // 4.99 and 5.01 to 5.00 and 5.00 leave every header figure where it
            // was, and a unit price re-rounded by the provider is a different line
            // even when the line amount it was multiplied into happens to agree.
            if ($line->taxAmount !== null && $other->taxAmount !== null && $line->taxAmount->amount !== $other->taxAmount->amount) {
                $moved[] = self::describe("line {$id} TaxAmount", $line->taxAmount->amount, $other->taxAmount->amount);
            }

            if (self::unitAmountMoved($line, $other)) {
                $moved[] = sprintf('line %s UnitAmount %s became %s', $id, self::unitAmountOf($line), self::unitAmountOf($other));
            }
        }

        foreach (array_diff_key($afterLines, $beforeLines) as $id => $line) {
            $moved[] = "line {$id} was added";
        }

        return $moved;
    }

    /**
     * Whether the unit price differs between two copies of a line.
     *
     * Compared at the exact (four-place) figure when both sides carry one, so a
     * 1.3333 that came back 1.33 is caught; at the cents figure when either side
     * has no exact one, so nothing is invented.
     */
    private static function unitAmountMoved(BankTransactionLine $before, BankTransactionLine $after): bool
    {
        if ($before->unitAmountExact !== null && $after->unitAmountExact !== null
            && is_numeric($before->unitAmountExact) && is_numeric($after->unitAmountExact)) {
            return abs((float) $before->unitAmountExact - (float) $after->unitAmountExact) > 0.00005;
        }

        return $before->unitAmount !== null && $after->unitAmount !== null
            && $before->unitAmount->amount !== $after->unitAmount->amount;
    }

    private static function unitAmountOf(BankTransactionLine $line): string
    {
        if ($line->unitAmountExact !== null && is_numeric($line->unitAmountExact)) {
            return (string) $line->unitAmountExact;
        }

        return $line->unitAmount === null ? '-' : number_format($line->unitAmount->amount / 100, 2, '.', '');
    }

    /**
     * @return array<string, BankTransactionLine>
     */
    private static function linesById(BankTransactionData $transaction): array
    {
        $lines = [];

        foreach ($transaction->lines as $index => $line) {
            $lines[$line->lineItemId ?? '#'.$index] = $line;
        }

        return $lines;
    }

    private static function describe(string $field, int $before, int $after): string
    {
        return sprintf('%s %s became %s', $field, number_format($before / 100, 2, '.', ''), number_format($after / 100, 2, '.', ''));
    }
}
