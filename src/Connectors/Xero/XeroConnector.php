<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Connectors\Xero;

use Hei\AccountingConnector\Connectors\AbstractConnector;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\AuthorizationResult;
use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\InvoiceData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\PaymentData;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\TaxCode;
use Hei\AccountingConnector\Data\TenantInfo;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Data\TrackingCategory;
use Hei\AccountingConnector\Data\TrackingOption;
use Hei\AccountingConnector\Enums\AccountClass;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\AccountingConnectorException;
use Hei\AccountingConnector\Exceptions\AuthenticationException;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\UnsupportedEntityTypeException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Http\HttpResponse;
use Hei\AccountingConnector\Support\Filename;

/**
 * Xero, over its plain JSON REST API.
 *
 * No vendored SDK. The wire protocol is small and fully reproduced here:
 * `xero-tenant-id` on every call, `Accept: application/json` because the Accounting
 * API still answers in XML by default, `Idempotency-Key` on creates, and raw bytes
 * with `Content-Type: application/octet-stream` for attachments.
 *
 * Rate limits worth designing around, all per tenant: 60 calls a minute, 5,000 a
 * day once the app is certified and 1,000 before that, and no more than 5 requests
 * in flight at once. The per-minute ceiling is shared with every other app the
 * customer has connected, so a busy organization can rate-limit us through no fault
 * of ours. HttpClient honours Retry-After; the host still needs to keep its queue
 * concurrency modest.
 */
final class XeroConnector extends AbstractConnector
{
    public const AUTHORIZE_URL = 'https://login.xero.com/identity/connect/authorize';

    public const TOKEN_URL = 'https://identity.xero.com/connect/token';

    public const CONNECTIONS_URL = 'https://api.xero.com/connections';

    public const API_BASE = 'https://api.xero.com/api.xro/2.0';

    /**
     * Xero caps an attachment at 10 MB, and rejects anything larger outright.
     *
     * This is the number that makes AttachmentSet worth having: an email rendered to
     * PDF passes it maybe half the time, and the same email rendered to PNG almost
     * always does.
     */
    public const ATTACHMENT_LIMIT_BYTES = 10 * 1024 * 1024;

    /**
     * Everything the connector needs. `offline_access` is what makes a refresh token
     * appear at all; without it the connection dies in 30 minutes and no amount of
     * refresh handling saves it.
     */
    public const DEFAULT_SCOPES = 'openid profile email offline_access '
        .'accounting.transactions accounting.contacts accounting.settings.read accounting.attachments';

    private ?XeroPayloadMapper $mapper = null;

    public function provider(): Provider
    {
        return Provider::Xero;
    }

    public function supports(EntityType $type): bool
    {
        // Xero represents every canonical type.
        return true;
    }

    public function attachmentSizeLimit(): int
    {
        return self::ATTACHMENT_LIMIT_BYTES;
    }

    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri ?? $this->redirectUri,
            'scope' => self::DEFAULT_SCOPES,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, array $parameters = [], ?string $redirectUri = null): AuthorizationResult
    {
        $response = $this->http->send(
            'POST',
            self::TOKEN_URL,
            [
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri ?? $this->redirectUri,
            ]),
            $this->provider(),
        );

        if ($response->failed()) {
            throw new AuthenticationException(
                'Xero refused the authorisation code: '.$this->describeError($response),
                $this->provider(),
                $this->describeError($response),
            );
        }

        $tokens = TokenSet::fromResponse($response->json());

        // Xero does not tell us which organization was authorised. A second call
        // does, and a user with access to several may have granted more than one.
        $tenants = $this->fetchTenants($tokens->accessToken);

        return new AuthorizationResult(
            provider: $this->provider(),
            tokens: $tokens,
            tenantId: $tenants[0]->id ?? null,
            tenants: $tenants,
        );
    }

    public function revoke(Connection $connection): bool
    {
        // Xero revokes a connection by deleting it, which needs the connection id
        // rather than the tenant id, so the list has to be walked first.
        try {
            try {
                // Best effort deserves a live token: with an expired one every
                // revocation attempt 401s and the connection is left dangling at
                // Xero. If the refresh itself fails, proceed with what we have and
                // let the listing fail into the false this is allowed to return.
                $connection = $this->fresh($connection);
            } catch (AccountingConnectorException) {
                // Deliberately ignored.
            }

            $listing = $this->http->send('GET', self::CONNECTIONS_URL, [
                'Authorization' => 'Bearer '.$connection->accessToken,
                'Accept' => 'application/json',
            ], null, $this->provider());

            if ($listing->failed()) {
                return false;
            }

            $decoded = json_decode($listing->body, true);

            foreach (is_array($decoded) ? $decoded : [] as $entry) {
                if (! is_array($entry) || ($entry['tenantId'] ?? null) !== $connection->tenantId) {
                    continue;
                }

                $deleted = $this->http->send(
                    'DELETE',
                    self::CONNECTIONS_URL.'/'.$entry['id'],
                    [
                        'Authorization' => 'Bearer '.$connection->accessToken,
                        'Accept' => 'application/json',
                    ],
                    null,
                    $this->provider(),
                );

                return $deleted->successful();
            }

            return false;
        } catch (\Throwable $e) {
            // Best effort by design. The local disconnect must happen regardless, or
            // the customer is left holding credentials they cannot get rid of.
            $this->logger->warning('Could not revoke the Xero connection at Xero.', [
                'connection' => $connection->reference,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function tenantInfo(Connection $connection): ?TenantInfo
    {
        $connection = $this->fresh($connection);

        $response = $this->get($connection, 'Organisation');

        if ($response->failed()) {
            return null;
        }

        $organisation = $response->get('Organisations.0');

        if (! is_array($organisation)) {
            return null;
        }

        return new TenantInfo(
            id: (string) ($organisation['OrganisationID'] ?? $connection->tenantId),
            name: (string) ($organisation['Name'] ?? ''),
            legalName: isset($organisation['LegalName']) ? (string) $organisation['LegalName'] : null,
            countryCode: isset($organisation['CountryCode']) ? (string) $organisation['CountryCode'] : null,
            currencyCode: isset($organisation['BaseCurrency']) ? (string) $organisation['BaseCurrency'] : null,
        );
    }

    public function chartOfAccounts(Connection $connection, bool $forceRefresh = false): array
    {
        // One request for every active account, rather than one per account type.
        // AccountingPipe asked Xero separately for bank accounts and expense
        // accounts, which is two calls out of a per-minute budget of sixty that the
        // customer shares with every other app they have connected.
        return $this->lookup(
            $connection,
            'chart_of_accounts',
            $forceRefresh,
            fn (): array => $this->fetchAccounts($connection),
            fn (array $row): Account => Account::fromArray($row),
        );
    }

    public function bankAccounts(Connection $connection, bool $forceRefresh = false): array
    {
        return Account::only($this->chartOfAccounts($connection, $forceRefresh), AccountClass::Bank);
    }

    public function trackingCategories(Connection $connection, bool $forceRefresh = false): array
    {
        return $this->lookup(
            $connection,
            'tracking_categories',
            $forceRefresh,
            fn (): array => $this->fetchTrackingCategories($connection),
            fn (array $row): TrackingCategory => TrackingCategory::fromArray($row),
        );
    }

    public function refreshLookups(Connection $connection): void
    {
        $this->chartOfAccounts($connection, forceRefresh: true);
        $this->taxCodes($connection, forceRefresh: true);
        $this->trackingCategories($connection, forceRefresh: true);
    }

    public function taxCodes(Connection $connection, bool $forceRefresh = false): array
    {
        return $this->lookup($connection, 'tax_codes', $forceRefresh, function () use ($connection): array {
            $connection = $this->fresh($connection);
            $response = $this->get($connection, 'TaxRates', ['where' => 'Status=="ACTIVE"']);

            if ($response->failed()) {
                $this->raise($response, $connection, 'the tax rate lookup');
            }

            $rates = $response->get('TaxRates', []);
            $codes = [];

            foreach (is_array($rates) ? $rates : [] as $rate) {
                if (! is_array($rate)) {
                    continue;
                }

                $canApply = static fn (string $key): bool => (bool) ($rate[$key] ?? false);

                $codes[] = new TaxCode(
                    reference: (string) ($rate['TaxType'] ?? ''),
                    name: (string) ($rate['Name'] ?? ''),
                    rate: (float) ($rate['EffectiveRate'] ?? $rate['DisplayTaxRate'] ?? 0),
                    isSalesTax: $canApply('CanApplyToRevenue'),
                    isPurchaseTax: $canApply('CanApplyToExpenses') || $canApply('CanApplyToAssets'),
                );
            }

            usort($codes, fn (TaxCode $a, TaxCode $b): int => strcasecmp($a->name, $b->name));

            return $codes;
        }, fn (array $row): TaxCode => TaxCode::fromArray($row));
    }

    /**
     * Resolve a contact to its Xero ContactID, creating it if Xero has never seen it.
     *
     * Checked against the entity map first. AccountingPipe queried Xero by name on
     * every single post, which is a wasted round trip against a 60-per-minute ceiling
     * and, worse, matches on a name the customer may have since edited.
     */
    public function resolveContact(ContactData $contact, Connection $connection): string
    {
        $connection = $this->fresh($connection);
        $mapKey = $contact->mapKey();

        $known = $this->entityMap->externalId($connection, $contact->role, $mapKey);

        if ($known !== null) {
            return $known;
        }

        // Xero's where clause is a string expression, so a quote in a vendor name
        // ("Bob's Plumbing") terminates it early and the query fails or, worse,
        // matches the wrong contact. Xero has no escape syntax for this, so the
        // lookup is skipped entirely for such names and the contact is created.
        $name = $contact->name;

        if (! str_contains($name, '"')) {
            $found = $this->get($connection, 'Contacts', ['where' => 'Name=="'.$name.'"']);

            if ($found->successful()) {
                $existing = $found->get('Contacts.0.ContactID');

                if (is_string($existing) && $existing !== '') {
                    $this->entityMap->remember($connection, $contact->role, $mapKey, $existing);

                    return $existing;
                }
            }
        }

        $created = $this->post($connection, 'Contacts', ['Contacts' => [$this->mapper()->contact($contact)]]);

        if ($created->failed()) {
            $this->raise($created, $connection, "creating the contact '{$name}'");
        }

        $contactId = $created->get('Contacts.0.ContactID');

        if (! is_string($contactId) || $contactId === '') {
            throw new ValidationException(
                "Xero accepted the contact '{$name}' but returned no ContactID.",
                $this->provider(),
            );
        }

        $this->entityMap->remember($connection, $contact->role, $mapKey, $contactId);

        return $contactId;
    }

    protected function requestRefresh(Connection $connection): TokenSet
    {
        $response = $this->http->send(
            'POST',
            self::TOKEN_URL,
            [
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $connection->refreshToken,
            ]),
            $this->provider(),
        );

        if ($response->failed()) {
            $reason = $this->describeError($response);

            // invalid_grant is Xero saying the refresh token is gone: either the
            // customer disconnected us, or it went 60 days unused. Retrying is futile.
            if ($response->status === 400 || $response->status === 401) {
                $this->announceRevocation($connection, $reason);

                throw ConnectionRevokedException::for($connection, $reason);
            }

            $this->raise($response, $connection, 'the token refresh');
        }

        $payload = $response->json();

        // A 200 with no token in it happens at Xero's edge occasionally. Building a
        // connection around an empty bearer token surfaces later as a misleading
        // "revoked", so it is refused here, matching the QuickBooks connector.
        if (empty($payload['access_token'])) {
            throw new AuthenticationException(
                'Xero returned a successful refresh with no access token in it.',
                $this->provider(),
            );
        }

        return TokenSet::fromResponse($payload);
    }

    protected function performCreate(
        EntityType $type,
        EntityPayload $payload,
        Connection $connection,
        ?string $idempotencyKey,
    ): ?string {
        if ($payload instanceof RawPayload) {
            return $this->createRaw($type, $payload, $connection, $idempotencyKey);
        }

        switch ($type) {
            case EntityType::Customer:
            case EntityType::Vendor:
                /** @var ContactData $contact */
                $contact = $this->as($payload, ContactData::class);

                return $this->resolveContact($contact, $connection);

            case EntityType::Bill:
                /** @var BillData $bill */
                $bill = $this->as($payload, BillData::class);

                return $this->createInvoiceLike(
                    $this->mapper()->bill($bill, $this->resolveContact($bill->vendorContact(), $connection)),
                    $connection,
                    $idempotencyKey,
                );

            case EntityType::Invoice:
                /** @var InvoiceData $invoice */
                $invoice = $this->as($payload, InvoiceData::class);

                return $this->createInvoiceLike(
                    $this->mapper()->invoice($invoice, $this->resolveContact($invoice->customerContact(), $connection)),
                    $connection,
                    $idempotencyKey,
                );

            case EntityType::Expense:
                /** @var ExpenseData $expense */
                $expense = $this->as($payload, ExpenseData::class);

                return $this->createExpense($expense, $connection, $idempotencyKey);

            case EntityType::Payment:
                /** @var PaymentData $payment */
                $payment = $this->as($payload, PaymentData::class);

                return $this->createPayment($payment, $connection, $idempotencyKey);

            case EntityType::Journal:
                /** @var JournalData $journal */
                $journal = $this->as($payload, JournalData::class);

                return $this->createJournal($journal, $connection, $idempotencyKey);

            default:
                // Unreachable today; keeps a future EntityType case from falling
                // through to an implicit null that reads as "accepted but no id".
                throw UnsupportedEntityTypeException::for($this->provider(), $type);
        }
    }

    protected function performUpdate(
        EntityType $type,
        string $externalId,
        EntityPayload $payload,
        Connection $connection,
    ): bool {
        [$resource, $idField] = $this->resourceFor($type);

        $body = $payload instanceof RawPayload
            ? $payload->body
            : $this->bodyForUpdate($type, $payload, $connection);

        $body[$idField] = $externalId;

        $response = $this->post($connection, $resource.'/'.$externalId, [$resource => [$body]]);

        if ($response->failed()) {
            $this->raise($response, $connection, "updating {$resource} {$externalId}");
        }

        return true;
    }

    protected function performAttach(
        EntityType $type,
        string $externalId,
        Attachment $attachment,
        Connection $connection,
    ): AttachmentResult {
        [$resource] = $this->resourceFor($type);

        $filename = Filename::sanitise($attachment->normalisedFilename());

        $response = $this->http->send(
            'POST',
            sprintf('%s/%s/%s/Attachments/%s', self::API_BASE, $resource, $externalId, rawurlencode($filename)),
            [
                'Authorization' => 'Bearer '.$connection->accessToken,
                'xero-tenant-id' => $connection->tenantId,
                'Accept' => 'application/json',
                // Raw bytes, not multipart. Xero takes the filename from the URL.
                'Content-Type' => 'application/octet-stream',
            ],
            $attachment->contents,
            $this->provider(),
        );

        if ($response->failed()) {
            return AttachmentResult::failed($this->describeError($response), $filename);
        }

        $attachmentId = $response->get('Attachments.0.AttachmentID');

        return AttachmentResult::success(
            filename: $filename,
            bytes: $attachment->size(),
            externalId: is_string($attachmentId) ? $attachmentId : null,
        );
    }

    protected function describeError(HttpResponse $response): string
    {
        $body = $response->json();

        // Xero has three error shapes depending on which layer refused you: the
        // identity server's OAuth error, the API's own Message, and the itemised
        // validation Elements. All three turn up in normal operation.
        if (isset($body['error'])) {
            $description = isset($body['error_description']) ? ': '.$body['error_description'] : '';

            return (string) $body['error'].$description;
        }

        $itemised = $this->describeErrors($response);

        if ($itemised !== []) {
            return implode('; ', $itemised);
        }

        if (isset($body['Message']) && is_string($body['Message'])) {
            return $body['Message'];
        }

        return 'HTTP '.$response->status;
    }

    protected function describeErrors(HttpResponse $response): array
    {
        $elements = $response->get('Elements', []);
        $messages = [];

        foreach (is_array($elements) ? $elements : [] as $element) {
            if (! is_array($element)) {
                continue;
            }

            foreach ($element['ValidationErrors'] ?? [] as $error) {
                if (is_array($error) && isset($error['Message']) && is_string($error['Message'])) {
                    $messages[] = $error['Message'];
                }
            }
        }

        return $messages;
    }

    /**
     * Bills and invoices are the same Xero resource and post identically.
     *
     * @param  array<string, mixed>  $body
     */
    private function createInvoiceLike(array $body, Connection $connection, ?string $idempotencyKey): ?string
    {
        $response = $this->post($connection, 'Invoices', ['Invoices' => [$body]], $idempotencyKey);

        if ($response->failed()) {
            $this->raise($response, $connection, 'creating the invoice');
        }

        $id = $response->get('Invoices.0.InvoiceID');

        return is_string($id) ? $id : null;
    }

    private function createExpense(ExpenseData $expense, Connection $connection, ?string $idempotencyKey): ?string
    {
        $bankAccount = $expense->bankAccount ?? $connection->setting('bank_account');

        if (! is_string($bankAccount) || $bankAccount === '') {
            // Fail here rather than at Xero. Xero's own refusal is a generic
            // validation error that does not name the missing field.
            throw new InvalidPayloadException(
                'A Xero spend-money transaction needs a bank account. Set one on the '
                .'ExpenseData or as the "bank_account" connection setting.',
                $this->provider(),
            );
        }

        $contactId = $this->resolveContact($expense->vendorContact(), $connection);

        $response = $this->post(
            $connection,
            'BankTransactions',
            ['BankTransactions' => [$this->mapper()->expense($expense, $contactId, $bankAccount)]],
            $idempotencyKey,
        );

        if ($response->failed()) {
            $this->raise($response, $connection, 'creating the spend-money transaction');
        }

        $id = $response->get('BankTransactions.0.BankTransactionID');

        return is_string($id) ? $id : null;
    }

    private function createPayment(PaymentData $payment, Connection $connection, ?string $idempotencyKey): ?string
    {
        $account = $payment->account ?? $connection->setting('bank_account');

        if (! is_string($account) || $account === '') {
            throw new InvalidPayloadException(
                'A Xero payment needs the account it was received into. Set one on the '
                .'PaymentData or as the "bank_account" connection setting.',
                $this->provider(),
            );
        }

        $response = $this->post(
            $connection,
            'Payments',
            ['Payments' => [$this->mapper()->payment($payment, $account)]],
            $idempotencyKey,
        );

        if ($response->failed()) {
            $this->raise($response, $connection, 'creating the payment');
        }

        $id = $response->get('Payments.0.PaymentID');

        return is_string($id) ? $id : null;
    }

    private function createJournal(JournalData $journal, Connection $connection, ?string $idempotencyKey): ?string
    {
        $response = $this->post(
            $connection,
            'ManualJournals',
            ['ManualJournals' => [$this->mapper()->journal($journal)]],
            $idempotencyKey,
        );

        if ($response->failed()) {
            $this->raise($response, $connection, 'creating the manual journal');
        }

        $id = $response->get('ManualJournals.0.ManualJournalID');

        return is_string($id) ? $id : null;
    }

    private function createRaw(EntityType $type, RawPayload $payload, Connection $connection, ?string $idempotencyKey): ?string
    {
        [$resource, $idField] = $this->resourceFor($type);

        $body = $payload->body;

        // A raw payload may carry a contact by name, exactly as AccountingPipe's
        // existing mappers emit it. Resolve it so a straight port keeps working.
        if (isset($body['Contact']['Name']) && ! isset($body['Contact']['ContactID'])) {
            $role = $type->isReceivable() ? EntityType::Customer : EntityType::Vendor;
            $body['Contact'] = ['ContactID' => $this->resolveContact(
                new ContactData(name: (string) $body['Contact']['Name'], role: $role),
                $connection,
            )];
        }

        $response = $this->post($connection, $resource, [$resource => [$body]], $idempotencyKey);

        if ($response->failed()) {
            $this->raise($response, $connection, "creating the raw {$resource}");
        }

        $id = $response->get($resource.'.0.'.$idField);

        return is_string($id) ? $id : null;
    }

    /**
     * @return array<int, Account>
     */
    private function fetchAccounts(Connection $connection): array
    {
        $connection = $this->fresh($connection);

        $response = $this->get($connection, 'Accounts', ['where' => 'Status=="ACTIVE"']);

        if ($response->failed()) {
            $this->raise($response, $connection, 'the account lookup');
        }

        $accounts = [];

        $rows = $response->get('Accounts', []);

        foreach (is_array($rows) ? $rows : [] as $account) {
            if (! is_array($account)) {
                continue;
            }

            $type = isset($account['Type']) ? (string) $account['Type'] : null;
            $code = isset($account['Code']) ? (string) $account['Code'] : null;
            $id = (string) ($account['AccountID'] ?? '');
            $class = AccountClass::fromXero($type);

            $accounts[] = new Account(
                id: $id,
                name: (string) ($account['Name'] ?? ''),
                code: $code,
                type: $type,
                class: $class,
                // Xero line items address an account by code, but a bank account on a
                // spend-money transaction is addressed by AccountID. Hence the split.
                reference: $class === AccountClass::Bank ? $id : ($code ?? $id),
                currency: isset($account['CurrencyCode']) ? (string) $account['CurrencyCode'] : null,
                bankAccountNumber: isset($account['BankAccountNumber']) ? (string) $account['BankAccountNumber'] : null,
            );
        }

        usort($accounts, fn (Account $a, Account $b): int => strcasecmp($a->name, $b->name));

        return $accounts;
    }

    /**
     * Tracking categories with their options, archived entries removed.
     *
     * Xero keeps returning archived categories and archived options long after a
     * customer stops using them. Offering an archived option in a dropdown produces
     * a post that Xero then rejects, so both are filtered here rather than left for
     * every host to rediscover. Ported from AccountingPipe.
     *
     * @return array<int, TrackingCategory>
     */
    private function fetchTrackingCategories(Connection $connection): array
    {
        $connection = $this->fresh($connection);

        $response = $this->get($connection, 'TrackingCategories');

        if ($response->failed()) {
            $this->raise($response, $connection, 'the tracking category lookup');
        }

        $rows = $response->get('TrackingCategories', []);
        $categories = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ($row['Status'] ?? null) === 'ARCHIVED') {
                continue;
            }

            $options = [];

            foreach (is_array($row['Options'] ?? null) ? $row['Options'] : [] as $option) {
                if (! is_array($option) || ($option['Status'] ?? null) === 'ARCHIVED') {
                    continue;
                }

                $options[] = new TrackingOption(
                    id: (string) ($option['TrackingOptionID'] ?? ''),
                    name: (string) ($option['Name'] ?? ''),
                );
            }

            usort($options, fn (TrackingOption $a, TrackingOption $b): int => strcasecmp($a->name, $b->name));

            $categories[] = new TrackingCategory(
                id: (string) ($row['TrackingCategoryID'] ?? ''),
                name: (string) ($row['Name'] ?? ''),
                options: $options,
            );
        }

        usort($categories, fn (TrackingCategory $a, TrackingCategory $b): int => strcasecmp($a->name, $b->name));

        return $categories;
    }

    /**
     * @return array<int, TenantInfo>
     */
    private function fetchTenants(string $accessToken): array
    {
        $response = $this->http->send('GET', self::CONNECTIONS_URL, [
            'Authorization' => 'Bearer '.$accessToken,
            'Accept' => 'application/json',
        ], null, $this->provider());

        if ($response->failed()) {
            return [];
        }

        // /connections answers with a bare JSON array, not an object, so the usual
        // keyed accessors do not apply.
        $decoded = json_decode($response->body, true);
        $tenants = [];

        foreach (is_array($decoded) ? $decoded : [] as $entry) {
            if (! is_array($entry) || ! isset($entry['tenantId'])) {
                continue;
            }

            $tenants[] = new TenantInfo(
                id: (string) $entry['tenantId'],
                name: (string) ($entry['tenantName'] ?? ''),
            );
        }

        return $tenants;
    }

    /**
     * The Xero resource and its id field for a canonical entity type.
     *
     * @return array{0: string, 1: string}
     */
    private function resourceFor(EntityType $type): array
    {
        return match ($type) {
            EntityType::Bill, EntityType::Invoice => ['Invoices', 'InvoiceID'],
            EntityType::Expense => ['BankTransactions', 'BankTransactionID'],
            EntityType::Payment => ['Payments', 'PaymentID'],
            EntityType::Journal => ['ManualJournals', 'ManualJournalID'],
            EntityType::Customer, EntityType::Vendor => ['Contacts', 'ContactID'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyForUpdate(EntityType $type, EntityPayload $payload, Connection $connection): array
    {
        switch ($type) {
            case EntityType::Bill:
                /** @var BillData $bill */
                $bill = $this->as($payload, BillData::class);

                return $this->mapper()->bill($bill, $this->resolveContact($bill->vendorContact(), $connection));

            case EntityType::Invoice:
                /** @var InvoiceData $invoice */
                $invoice = $this->as($payload, InvoiceData::class);

                return $this->mapper()->invoice($invoice, $this->resolveContact($invoice->customerContact(), $connection));

            case EntityType::Expense:
                /** @var ExpenseData $expense */
                $expense = $this->as($payload, ExpenseData::class);
                $bankAccount = $expense->bankAccount ?? $connection->setting('bank_account');

                if (! is_string($bankAccount) || $bankAccount === '') {
                    throw new InvalidPayloadException(
                        'A Xero spend-money transaction needs a bank account.',
                        $this->provider(),
                    );
                }

                return $this->mapper()->expense(
                    $expense,
                    $this->resolveContact($expense->vendorContact(), $connection),
                    $bankAccount,
                );

            case EntityType::Journal:
                /** @var JournalData $journal */
                $journal = $this->as($payload, JournalData::class);

                return $this->mapper()->journal($journal);

            case EntityType::Customer:
            case EntityType::Vendor:
                /** @var ContactData $contact */
                $contact = $this->as($payload, ContactData::class);

                return $this->mapper()->contact($contact);

            case EntityType::Payment:
                throw new InvalidPayloadException(
                    'Xero payments cannot be updated. Delete the payment and post a new one.',
                    $this->provider(),
                );

            default:
                throw UnsupportedEntityTypeException::for($this->provider(), $type);
        }
    }

    private function mapper(): XeroPayloadMapper
    {
        // Lazily built so the constructor signature stays inherited and callers do
        // not have to know the mapper exists.
        return $this->mapper ??= new XeroPayloadMapper;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(Connection $connection, string $resource, array $query = []): HttpResponse
    {
        $url = self::API_BASE.'/'.$resource;

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->http->send('GET', $url, $this->headers($connection), null, $this->provider());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(Connection $connection, string $resource, array $body, ?string $idempotencyKey = null): HttpResponse
    {
        $headers = $this->headers($connection) + ['Content-Type' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->http->send(
            'POST',
            self::API_BASE.'/'.$resource,
            $headers,
            json_encode($body, JSON_THROW_ON_ERROR),
            $this->provider(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(Connection $connection): array
    {
        return [
            'Authorization' => 'Bearer '.$connection->accessToken,
            'xero-tenant-id' => $connection->tenantId,
            // Without this the Accounting API answers in XML.
            'Accept' => 'application/json',
        ];
    }
}
