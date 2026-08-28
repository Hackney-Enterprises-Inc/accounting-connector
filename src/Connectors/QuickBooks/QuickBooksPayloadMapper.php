<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Connectors\QuickBooks;

use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\InvoiceData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\JournalLine;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\PaymentData;

/**
 * Canonical payloads to QuickBooks Online's JSON.
 *
 * QuickBooks differs from Xero in ways that matter here:
 *
 *  - Lines carry a single Amount rather than a quantity and a unit price. Sending
 *    quantity is possible on item-based lines but not on the account-based lines
 *    this package emits, so quantity is folded into the amount.
 *  - Every reference is an opaque per-company id, never a code. An account id from
 *    one company means nothing in another.
 *  - Dates are plain ISO strings both ways, with none of Xero's /Date(...)/ format.
 *  - There is no tracking-category equivalent, so TrackingRefs are dropped.
 */
final class QuickBooksPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function bill(BillData $bill, string $vendorId): array
    {
        $payload = [
            'VendorRef' => ['value' => $vendorId],
            'TxnDate' => $bill->date->format('Y-m-d'),
            'CurrencyRef' => ['value' => strtoupper($bill->currency)],
            // Required on non-US companies; US companies ignore it. Omitting it made
            // Intuit treat tax-inclusive amounts as exclusive and post totals off by
            // the tax, silently.
            'GlobalTaxCalculation' => $bill->lineAmountType->toQuickBooks(),
            'Line' => array_map($this->expenseLine(...), $bill->lines),
        ];

        if ($bill->dueDate !== null) {
            $payload['DueDate'] = $bill->dueDate->format('Y-m-d');
        }

        if ($bill->documentNumber !== null) {
            $payload['DocNumber'] = $bill->documentNumber;
        }

        if ($bill->reference !== null) {
            $payload['PrivateNote'] = $bill->reference;
        }

        return $payload;
    }

    /**
     * A Purchase: money that has already left an account.
     *
     * @return array<string, mixed>
     */
    public function expense(ExpenseData $expense, string $vendorId, string $accountId): array
    {
        $payload = [
            'PaymentType' => $expense->paymentMethod ?? 'Cash',
            'AccountRef' => ['value' => $accountId],
            'EntityRef' => ['value' => $vendorId, 'type' => 'Vendor'],
            'TxnDate' => $expense->date->format('Y-m-d'),
            'CurrencyRef' => ['value' => strtoupper($expense->currency)],
            'GlobalTaxCalculation' => $expense->lineAmountType->toQuickBooks(),
            'Line' => array_map($this->expenseLine(...), $expense->lines),
        ];

        if ($expense->reference !== null) {
            $payload['PrivateNote'] = $expense->reference;
        }

        return $payload;
    }

    /**
     * @param  array<int, string|null>  $lineItemIds  One resolved QBO item id per line; null lets the company default apply.
     * @return array<string, mixed>
     */
    public function invoice(InvoiceData $invoice, string $customerId, array $lineItemIds = []): array
    {
        $payload = [
            'CustomerRef' => ['value' => $customerId],
            'TxnDate' => $invoice->date->format('Y-m-d'),
            'CurrencyRef' => ['value' => strtoupper($invoice->currency)],
            'GlobalTaxCalculation' => $invoice->lineAmountType->toQuickBooks(),
            // array_map pads the shorter array with nulls, so absent item ids simply
            // produce lines without an ItemRef.
            'Line' => array_map($this->salesLine(...), $invoice->lines, $lineItemIds),
        ];

        if ($invoice->dueDate !== null) {
            $payload['DueDate'] = $invoice->dueDate->format('Y-m-d');
        }

        if ($invoice->documentNumber !== null) {
            $payload['DocNumber'] = $invoice->documentNumber;
        }

        if ($invoice->reference !== null) {
            $payload['PrivateNote'] = $invoice->reference;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(PaymentData $payment, string $customerId, ?string $depositAccountId): array
    {
        $body = [
            'CustomerRef' => ['value' => $customerId],
            'TotalAmt' => $payment->amount->toDecimal(),
            'TxnDate' => $payment->date->format('Y-m-d'),
            'CurrencyRef' => ['value' => strtoupper($payment->currency)],
            'Line' => [[
                'Amount' => $payment->amount->toDecimal(),
                'LinkedTxn' => [[
                    'TxnId' => $payment->invoiceExternalId,
                    'TxnType' => 'Invoice',
                ]],
            ]],
        ];

        if ($depositAccountId !== null) {
            $body['DepositToAccountRef'] = ['value' => $depositAccountId];
        }

        if ($payment->reference !== null) {
            $body['PrivateNote'] = $payment->reference;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function journal(JournalData $journal): array
    {
        return [
            'TxnDate' => $journal->date->format('Y-m-d'),
            'CurrencyRef' => ['value' => strtoupper($journal->currency)],
            'PrivateNote' => $journal->narration,
            'Line' => array_map($this->journalLine(...), $journal->lines),
        ];
    }

    /**
     * A Customer or a Vendor. QuickBooks keeps them as separate resources.
     *
     * @return array<string, mixed>
     */
    public function contact(ContactData $contact): array
    {
        $payload = ['DisplayName' => $contact->name];

        if ($contact->email !== null) {
            $payload['PrimaryEmailAddr'] = ['Address' => $contact->email];
        }

        if ($contact->phone !== null) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => $contact->phone];
        }

        if ($contact->taxNumber !== null) {
            $payload['TaxIdentifier'] = $contact->taxNumber;
        }

        if ($contact->address !== null && ! $contact->address->isEmpty()) {
            $payload['BillAddr'] = array_filter([
                'Line1' => $contact->address->line1,
                'Line2' => $contact->address->line2,
                'City' => $contact->address->city,
                'CountrySubDivisionCode' => $contact->address->region,
                'PostalCode' => $contact->address->postalCode,
                'Country' => $contact->address->country,
            ], fn (?string $value): bool => $value !== null);
        }

        return $payload;
    }

    /**
     * An account-based expense line, for bills and purchases.
     *
     * @return array<string, mixed>
     */
    private function expenseLine(LineItem $line): array
    {
        $detail = [];

        if ($line->accountCode !== null) {
            $detail['AccountRef'] = ['value' => $line->accountCode];
        }

        if ($line->taxCode !== null) {
            $detail['TaxCodeRef'] = ['value' => $line->taxCode];
        }

        return [
            'DetailType' => 'AccountBasedExpenseLineDetail',
            // Quantity is folded in: account-based lines have no quantity field, so a
            // line of 3 at 25.00 must arrive as a single amount of 75.00.
            'Amount' => $line->total()->toDecimal(),
            'Description' => $line->description,
            'AccountBasedExpenseLineDetail' => $detail,
        ];
    }

    /**
     * A sales line, for invoices.
     *
     * QuickBooks sales lines are item-based. Sending ItemAccountRef instead of an
     * ItemRef looks like it should address the income account directly, but the
     * live API ignores it and quietly attaches the line to the company's default
     * item — posting income to whatever account THAT item is wired to. So the
     * connector resolves each line's income account to a real item first and the
     * account travels via ItemRef.
     *
     * @return array<string, mixed>
     */
    private function salesLine(LineItem $line, ?string $itemId = null): array
    {
        $detail = [
            'Qty' => $line->quantity,
        ];

        if ($itemId !== null) {
            $detail['ItemRef'] = ['value' => $itemId];
        }

        if ($line->unitAmount !== null) {
            $detail['UnitPrice'] = $line->unitAmount->toDecimal();
        }

        if ($line->taxCode !== null) {
            $detail['TaxCodeRef'] = ['value' => $line->taxCode];
        }

        return [
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => $line->total()->toDecimal(),
            'Description' => $line->description,
            'SalesItemLineDetail' => $detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function journalLine(JournalLine $line): array
    {
        $detail = [
            // Canonical journal lines are signed, Xero-style. QuickBooks wants an
            // explicit posting type and an unsigned amount, so the sign is translated
            // here and the amount sent as its absolute value.
            'PostingType' => $line->isDebit() ? 'Debit' : 'Credit',
            'AccountRef' => ['value' => $line->accountCode],
        ];

        if ($line->taxCode !== null) {
            $detail['TaxCodeRef'] = ['value' => $line->taxCode];
        }

        return [
            'DetailType' => 'JournalEntryLineDetail',
            'Amount' => abs($line->amount->toDecimal()),
            'Description' => $line->description ?? '',
            'JournalEntryLineDetail' => $detail,
        ];
    }
}
