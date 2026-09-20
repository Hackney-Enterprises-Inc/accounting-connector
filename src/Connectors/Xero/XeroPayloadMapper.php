<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Connectors\Xero;

use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\InvoiceData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\JournalLine;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\PaymentData;
use Hei\AccountingConnector\Data\TrackingRef;

/**
 * Canonical payloads to Xero's JSON.
 *
 * Pure translation: no HTTP, no state, no decisions about what should be posted.
 * That makes the whole mapping testable without a network, which is most of why it
 * is separated from the connector at all.
 */
final class XeroPayloadMapper
{
    /**
     * @param  string  $contactId  Already resolved by the connector.
     * @return array<string, mixed>
     */
    public function bill(BillData $bill, string $contactId): array
    {
        $payload = [
            'Type' => 'ACCPAY',
            'Contact' => ['ContactID' => $contactId],
            'Date' => XeroDate::toXero($bill->date),
            'LineAmountTypes' => $bill->lineAmountType->toXero(),
            'Status' => $bill->status->toXero(),
            'CurrencyCode' => strtoupper($bill->currency),
            'LineItems' => array_map($this->lineItem(...), $bill->lines),
        ];

        if ($bill->dueDate !== null) {
            $payload['DueDate'] = XeroDate::toXero($bill->dueDate);
        }

        if ($bill->documentNumber !== null) {
            $payload['InvoiceNumber'] = $bill->documentNumber;
        }

        if ($bill->reference !== null) {
            $payload['Reference'] = $bill->reference;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(InvoiceData $invoice, string $contactId): array
    {
        $payload = [
            'Type' => 'ACCREC',
            'Contact' => ['ContactID' => $contactId],
            'Date' => XeroDate::toXero($invoice->date),
            'LineAmountTypes' => $invoice->lineAmountType->toXero(),
            'Status' => $invoice->status->toXero(),
            'CurrencyCode' => strtoupper($invoice->currency),
            'LineItems' => array_map($this->lineItem(...), $invoice->lines),
        ];

        if ($invoice->dueDate !== null) {
            $payload['DueDate'] = XeroDate::toXero($invoice->dueDate);
        }

        if ($invoice->documentNumber !== null) {
            $payload['InvoiceNumber'] = $invoice->documentNumber;
        }

        if ($invoice->reference !== null) {
            $payload['Reference'] = $invoice->reference;
        }

        return $payload;
    }

    /**
     * A spend-money bank transaction.
     *
     * The bank account is mandatory and is checked by the connector before this
     * runs: Xero rejects a SPEND without one, with a message that does not say so.
     *
     * @return array<string, mixed>
     */
    public function expense(ExpenseData $expense, string $contactId, string $bankAccountId): array
    {
        $payload = [
            // SPEND for money out, RECEIVE for a refund or credit back in. The amounts
            // are positive either way; the type is the sign.
            'Type' => $expense->direction->bankTransactionType()->value,
            'Contact' => ['ContactID' => $contactId],
            'Date' => XeroDate::toXero($expense->date),
            'BankAccount' => ['AccountID' => $bankAccountId],
            'LineAmountTypes' => $expense->lineAmountType->toXero(),
            'Status' => $expense->status->toXero(),
            'CurrencyCode' => strtoupper($expense->currency),
            'LineItems' => array_map($this->lineItem(...), $expense->lines),
        ];

        if ($expense->reference !== null) {
            $payload['Reference'] = $expense->reference;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(PaymentData $payment, string $accountId): array
    {
        $payload = [
            'Invoice' => ['InvoiceID' => $payment->invoiceExternalId],
            'Account' => ['AccountID' => $accountId],
            'Date' => XeroDate::toXero($payment->date),
            'Amount' => $payment->amount->toDecimal(),
        ];

        if ($payment->reference !== null) {
            $payload['Reference'] = $payment->reference;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function journal(JournalData $journal): array
    {
        return [
            'Narration' => $journal->narration,
            'Date' => XeroDate::toXero($journal->date),
            'LineAmountTypes' => 'NoTax',
            'Status' => $journal->status->toXeroJournal(),
            'JournalLines' => array_map($this->journalLine(...), $journal->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contact(ContactData $contact): array
    {
        $payload = [
            'Name' => $contact->name,
            'IsSupplier' => $contact->role->isPayable(),
            'IsCustomer' => $contact->role->isReceivable(),
        ];

        if ($contact->email !== null) {
            $payload['EmailAddress'] = $contact->email;
        }

        if ($contact->taxNumber !== null) {
            $payload['TaxNumber'] = $contact->taxNumber;
        }

        if ($contact->phone !== null) {
            $payload['Phones'] = [[
                'PhoneType' => 'DEFAULT',
                'PhoneNumber' => $contact->phone,
            ]];
        }

        if ($contact->address !== null && ! $contact->address->isEmpty()) {
            $payload['Addresses'] = [array_filter([
                'AddressType' => 'STREET',
                'AddressLine1' => $contact->address->line1,
                'AddressLine2' => $contact->address->line2,
                'City' => $contact->address->city,
                'Region' => $contact->address->region,
                'PostalCode' => $contact->address->postalCode,
                'Country' => $contact->address->country,
            ], fn (?string $value): bool => $value !== null)];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function lineItem(LineItem $line): array
    {
        $payload = [
            'Description' => $line->description,
            'TaxType' => $line->taxCode ?? 'NONE',
        ];

        if ($line->accountCode !== null) {
            $payload['AccountCode'] = $line->accountCode;
        }

        // Send one form or the other, never both. Given Quantity, UnitAmount and
        // LineAmount together, Xero recalculates the line from the first two and
        // silently discards the third, which is how an allocation split posts to the
        // wrong amount.
        if ($line->isFlatAmount()) {
            $payload['LineAmount'] = $line->total()->toDecimal();
        } else {
            $payload['Quantity'] = $line->quantity;
            $payload['UnitAmount'] = $line->unitAmount?->toDecimal() ?? 0.0;
        }

        if ($line->hasTracking()) {
            $payload['Tracking'] = array_map($this->tracking(...), $line->tracking);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function journalLine(JournalLine $line): array
    {
        $payload = [
            'LineAmount' => $line->amount->toDecimal(),
            'AccountCode' => $line->accountCode,
        ];

        if ($line->description !== null) {
            $payload['Description'] = $line->description;
        }

        if ($line->taxCode !== null) {
            $payload['TaxType'] = $line->taxCode;
        }

        if ($line->tracking !== []) {
            $payload['Tracking'] = array_map($this->tracking(...), $line->tracking);
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function tracking(TrackingRef $ref): array
    {
        $payload = [
            'TrackingCategoryID' => $ref->categoryId,
            'TrackingOptionID' => $ref->optionId,
        ];

        if ($ref->categoryName !== null) {
            $payload['Name'] = $ref->categoryName;
        }

        if ($ref->optionName !== null) {
            $payload['Option'] = $ref->optionName;
        }

        return $payload;
    }
}
