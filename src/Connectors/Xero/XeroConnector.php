<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Connectors\Xero;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hei\AccountingConnector\Connectors\AbstractConnector;
use Hei\AccountingConnector\Contracts\CodesBankTransactions;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Contracts\FindsContacts;
use Hei\AccountingConnector\Contracts\ListsContacts;
use Hei\AccountingConnector\Contracts\ReadsBankTransactions;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentResult;
use Hei\AccountingConnector\Data\AuthorizationResult;
use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionLine;
use Hei\AccountingConnector\Data\BankTransactionPage;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\Contact;
use Hei\AccountingConnector\Data\ContactData;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\InvoiceData;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Data\PaymentData;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\RecodeExpectation;
use Hei\AccountingConnector\Data\RecodeResult;
use Hei\AccountingConnector\Data\TaxCode;
use Hei\AccountingConnector\Data\TenantInfo;
use Hei\AccountingConnector\Data\TokenSet;
use Hei\AccountingConnector\Data\TrackingCategory;
use Hei\AccountingConnector\Data\TrackingOption;
use Hei\AccountingConnector\Data\TrackingRef;
use Hei\AccountingConnector\Enums\AccountClass;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\LineAmountType;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\AccountingConnectorException;
use Hei\AccountingConnector\Exceptions\AuthenticationException;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\NotFoundException;
use Hei\AccountingConnector\Exceptions\PreconditionFailedException;
use Hei\AccountingConnector\Exceptions\RecodeMovedMoneyException;
use Hei\AccountingConnector\Exceptions\UnsupportedEntityTypeException;
use Hei\AccountingConnector\Exceptions\ValidationException;
use Hei\AccountingConnector\Http\HttpResponse;
use Hei\AccountingConnector\Support\Filename;
use Hei\AccountingConnector\Support\RecodeInvariants;

/**
 * Xero, over its plain JSON REST API.
 *
 * No vendored SDK. The wire protocol is small and fully reproduced here:
 * `xero-tenant-id` on every call, `Accept: application/json` because the Accounting
 * API still answers in XML by default, `Idempotency-Key` on creates, and raw bytes
 * with `Content-Type: application/octet-stream` for attachments.
 *
 * Rate limits worth designing around, each measured per app per connected
 * organisation: 60 calls a minute, 5,000 a day once the app is certified and 1,000
 * before that, and no more than 5 requests in flight at once. They are ours alone;
 * another app the customer has connected spends its own allowance, not this one.
 * HttpClient honours Retry-After and asks a RequestGate before every attempt; the
 * host still needs to keep its queue concurrency modest and to budget a long walk.
 */
final class XeroConnector extends AbstractConnector implements CodesBankTransactions, FindsContacts, ListsContacts, ReadsBankTransactions
{
    /** Contacts per GET Contacts page; Xero's own default and documented page size. */
    public const CONTACT_PAGE_SIZE = 100;

    private const EMPTY_GUID = '00000000-0000-0000-0000-000000000000';

    public const AUTHORIZE_URL = 'https://login.xero.com/identity/connect/authorize';

    public const TOKEN_URL = 'https://identity.xero.com/connect/token';

    public const CONNECTIONS_URL = 'https://api.xero.com/connections';

    public const API_BASE = 'https://api.xero.com/api.xro/2.0';

    /**
     * The query every bank transaction read AND write carries. Xero rounds unit
     * amounts to two places unless asked for four, on the way out and on the way
     * in, and a price rounded to cents times a quantity is a different line.
     *
     * @var array<string, int>
     */
    private const UNIT_DP = ['unitdp' => 4];

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

    /**
     * Rows per page when a query does not say. See BankTransactionQuery::DEFAULT_PAGE_SIZE
     * for why the default is the documented figure rather than the spec's.
     */
    private int $bankTransactionPageSize = BankTransactionQuery::DEFAULT_PAGE_SIZE;

    public function provider(): Provider
    {
        return Provider::Xero;
    }

    /**
     * Rows per bank transaction page when a query leaves it unset.
     *
     * Fluent rather than a constructor argument so the constructor stays inherited.
     */
    public function usingBankTransactionPageSize(int $pageSize): self
    {
        if ($pageSize < 1 || $pageSize > BankTransactionQuery::MAX_PAGE_SIZE) {
            throw new InvalidPayloadException(sprintf(
                'A bank transaction page size must be between 1 and %d, got %d.',
                BankTransactionQuery::MAX_PAGE_SIZE,
                $pageSize,
            ), $this->provider());
        }

        $this->bankTransactionPageSize = $pageSize;

        return $this;
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
            self::LOOKUP_CHART_OF_ACCOUNTS,
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
            $connection->tenantId,
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
                systemAccount: isset($account['SystemAccount']) && $account['SystemAccount'] !== '' ? (string) $account['SystemAccount'] : null,
                description: isset($account['Description']) && $account['Description'] !== '' ? (string) $account['Description'] : null,
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

    /**
     * One page of bank transactions, filtered at Xero rather than here.
     *
     * A company with a live bank feed holds tens of thousands of these and this app
     * is allowed sixty calls a minute and five thousand a day against each
     * organisation, so everything the caller asked to narrow by becomes part of the
     * `where` expression and the modified-since instant becomes a header.
     *
     * Every read asks for `unitdp=4`. Xero rounds unit amounts to two places unless
     * told otherwise, and a line read at two places cannot be sent back without
     * moving the money on a transaction whose unit price had four.
     */
    public function listBankTransactions(Connection $connection, BankTransactionQuery $query): BankTransactionPage
    {
        $connection = $this->fresh($connection);

        $pageSize = $query->effectivePageSize($this->bankTransactionPageSize);

        $parameters = [
            'page' => $query->page,
            'pageSize' => $pageSize,
        ] + self::UNIT_DP;

        if ($query->order !== null) {
            $parameters['order'] = $query->order;
        }

        $where = $this->bankTransactionWhere($query);

        if ($where !== null) {
            $parameters['where'] = $where;
        }

        $response = $this->get(
            $connection,
            'BankTransactions',
            $parameters,
            $this->modifiedSinceHeader($query->modifiedSince),
        );

        /*
         * A 304 is the successful answer to "has anything changed", not a failure:
         * Xero sends it when If-Modified-Since is newer than every candidate row.
         */
        if ($response->status === 304) {
            return new BankTransactionPage([], $query->page, $pageSize);
        }

        if ($response->failed()) {
            $this->raise($response, $connection, 'the bank transaction list');
        }

        $rows = $response->get('BankTransactions', []);
        $transactions = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $transactions[] = $this->bankTransaction($row);
            }
        }

        $count = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        return new BankTransactionPage(
            $transactions,
            $query->page,
            $pageSize,
            itemCount: $count($response->get('pagination.itemCount')),
            pageCount: $count($response->get('pagination.pageCount')),
        );
    }

    public function findBankTransaction(Connection $connection, string $externalId): ?BankTransactionData
    {
        $connection = $this->fresh($connection);

        $response = $this->get($connection, 'BankTransactions/'.rawurlencode($externalId), self::UNIT_DP);

        /*
         * Xero answers a missing bank transaction with a 404, and a host asking about
         * one it mirrored earlier is asking precisely because the row may be gone.
         * That is an answer, not an error, so it comes back as null; every other
         * failure still raises.
         */
        if ($response->status === 404) {
            return null;
        }

        if ($response->failed()) {
            $this->raise($response, $connection, "the bank transaction {$externalId}");
        }

        $row = $response->get('BankTransactions.0');

        return is_array($row) ? $this->bankTransaction($row) : null;
    }

    /**
     * Recode an existing bank transaction without disturbing anything else on it.
     *
     * Xero has no partial update: a POST to /BankTransactions replaces the whole
     * transaction, and a field left out of the body is a field cleared or defaulted.
     * So this reads the transaction first and sends every part of it back, with only
     * the coding and, when asked, the contact changed. See {@see self::recodedBody()}
     * for what "every part" has to include.
     *
     * The three guards, in order, are the contract's: the expectation against the
     * read, the tax guard against what Xero would recompute, and the moved-money
     * check against what Xero answered. The first two run before any POST.
     */
    public function recodeBankTransaction(
        Connection $connection,
        string $externalId,
        BankTransactionChange $change,
        ?RecodeExpectation $expectation = null,
        ?string $idempotencyKey = null,
    ): RecodeResult {
        if ($change->isEmpty()) {
            throw new InvalidPayloadException(
                "A recode of {$externalId} that changes nothing was refused before any request.",
                $this->provider(),
            );
        }

        $connection = $this->fresh($connection);

        $current = $this->findBankTransaction($connection, $externalId);

        if ($current === null) {
            throw new NotFoundException(
                "Xero has no bank transaction {$externalId} to recode.",
                $this->provider(),
            );
        }

        if ($expectation !== null) {
            $differences = $expectation->differences($current);

            /*
             * The read may already show the change applied: an earlier attempt
             * landed and its response was lost, and this is the retry the
             * idempotency key exists for. Judged strictly: every line the change
             * targets carries what the change sets, every other line still carries
             * what the expectation says, and the provider's stamp has moved on, not
             * back. Then nothing is sent and the read is the result. A person who
             * coded the line to the very same account in between is indistinguishable
             * from that and is treated the same way.
             */
            if ($differences !== [] && $this->alreadyLanded($expectation, $change, $current)) {
                return new RecodeResult(
                    $current->withAccountCodes($expectation->accountCodesByLine, $expectation->updatedDateUtc),
                    $current,
                    recovered: true,
                );
            }

            if ($differences !== []) {
                throw new PreconditionFailedException(
                    sprintf(
                        'The bank transaction %s changed since the recode was decided: %s.',
                        $externalId,
                        implode('; ', $differences),
                    ),
                    $current,
                    $differences,
                    $this->provider(),
                );
            }
        }

        $this->refuseWhenTaxWouldMove($connection, $current);
        $type = $this->requireKnownType($current);

        // unitdp=4 on the write as on the reads: without it Xero rounds the
        // UnitAmount it is handed to two places before recomputing the line, so a
        // 1.3333 read back at four places lands as 1.33 and a three-unit line moves
        // by a cent (invariant 15). The row Xero answers with comes back at four
        // places too, which is what the moved-money check compares.
        $response = $this->post($connection, 'BankTransactions', [
            'BankTransactions' => [$this->recodedBody($current, $change, $type)],
        ], $idempotencyKey, self::UNIT_DP);

        if ($response->failed()) {
            $this->raise($response, $connection, "recoding the bank transaction {$externalId}");
        }

        $row = $response->get('BankTransactions.0');

        if (! is_array($row)) {
            throw new ValidationException(
                "Xero accepted the recoding of {$externalId} but returned no transaction.",
                $this->provider(),
            );
        }

        $after = $this->bankTransaction($row);
        $moved = RecodeInvariants::movedMoney($current, $after);

        if ($moved !== []) {
            throw new RecodeMovedMoneyException(
                sprintf('Recoding the bank transaction %s moved money: %s.', $externalId, implode('; ', $moved)),
                $current,
                $after,
                $moved,
                $this->provider(),
            );
        }

        return new RecodeResult($current, $after);
    }

    /**
     * The original coding-only write, kept as a wrapper with no expectation.
     *
     * @param  array<int, LineCoding>  $codings
     */
    public function updateBankTransactionCoding(
        Connection $connection,
        string $externalId,
        array $codings,
    ): BankTransactionData {
        return $this->recodeBankTransaction($connection, $externalId, new BankTransactionChange($codings))->after;
    }

    /**
     * Delete a spend or receive money transaction, and say what Xero holds now.
     *
     * Xero deletes these by status: a POST to the transaction's own URL with
     * `Status: DELETED` (the status-codes page lists only AUTHORISED and DELETED
     * for bank transactions; VOIDED belongs to the prepayment and overpayment
     * variants). What a GET returns afterwards, a 404 or the row with its new
     * status, is not documented; either way the answer here is a transaction whose
     * status is DELETED, so a host has one shape to test against. A transaction
     * that is already gone when the POST is made is the same answer: a 404 on the
     * delete is "done", not an error, because the host asking is asking precisely
     * because it may have been deleted in Xero already.
     *
     * Nothing here checks reconciliation or who created the transaction; those are
     * the host's invariants, checked against a fresh read before it calls this.
     */
    public function deleteBankTransaction(Connection $connection, string $externalId, ?string $idempotencyKey = null): BankTransactionData
    {
        if (trim($externalId) === '') {
            throw new InvalidPayloadException('A bank transaction id is needed to delete one.', $this->provider());
        }

        $connection = $this->fresh($connection);

        $response = $this->post(
            $connection,
            'BankTransactions/'.rawurlencode($externalId),
            ['BankTransactions' => [['BankTransactionID' => $externalId, 'Status' => 'DELETED']]],
            $idempotencyKey,
            self::UNIT_DP,
        );

        if ($response->status === 404) {
            return $this->deletedPlaceholder($externalId);
        }

        if ($response->failed()) {
            $this->raise($response, $connection, "deleting the bank transaction {$externalId}");
        }

        $row = $response->get('BankTransactions.0');
        $after = is_array($row) ? $this->bankTransaction($row) : $this->findBankTransaction($connection, $externalId);

        if ($after === null) {
            return $this->deletedPlaceholder($externalId);
        }

        if (! in_array(strtoupper((string) $after->status), ['DELETED', 'VOIDED'], true)) {
            throw new ValidationException(
                sprintf(
                    'Xero accepted the delete of the bank transaction %s but still reports it as %s.',
                    $externalId,
                    $after->status ?? 'unknown',
                ),
                $this->provider(),
            );
        }

        return $after;
    }

    /**
     * What a transaction Xero no longer returns looks like to a host: gone, by id.
     */
    private function deletedPlaceholder(string $externalId): BankTransactionData
    {
        return new BankTransactionData(
            id: $externalId,
            type: null,
            date: null,
            total: Money::zero(),
            status: 'DELETED',
        );
    }

    /**
     * The contact with exactly this name, without creating one.
     *
     * The same lookup {@see self::resolveContact()} makes before it creates, minus
     * the create and minus the entity map: a match wants the customer's own answer,
     * not a mapping a post made earlier.
     */
    public function findContactByName(Connection $connection, string $name): ?Contact
    {
        $name = trim($name);

        // Xero's where clause has no escape syntax, so a name with a double quote
        // cannot be asked for safely. Unknown, not found.
        if ($name === '' || str_contains($name, '"')) {
            return null;
        }

        $connection = $this->fresh($connection);

        $response = $this->get($connection, 'Contacts', ['where' => 'Name=="'.$name.'"']);

        if ($response->failed()) {
            $this->raise($response, $connection, "the contact lookup for '{$name}'");
        }

        $row = $response->get('Contacts.0');

        if (! is_array($row)) {
            return null;
        }

        $id = $row['ContactID'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $this->contact($row, $name);
    }

    /**
     * Every contact, customers and archived ones included, paged as the caller
     * iterates (see ListsContacts for why nothing is filtered by supplier).
     *
     * GET Contacts with `page` and `pageSize` 100 and `includeArchived=true`. Paging is
     * also what makes Xero return MergedToContactID at all: its docs say the field is
     * "only returned when using paging or when fetching a contact by ContactId". The
     * `summaryOnly` option is deliberately not used: Xero documents that it drops
     * IsSupplier and IsCustomer among others, and a flag silently missing would read
     * as false. Ordered by the immutable ContactID: sorting by UpdatedDateUTC
     * would move an edited contact to the end and shift untouched contacts across
     * page boundaries, potentially skipping them. This is still a live listing,
     * not a snapshot; additions or removals can change page membership. A 304 answer to
     * If-Modified-Since means nothing changed and ends the listing. Each page re-checks
     * the token, so a long walk survives an access token expiring part way.
     *
     * @return \Generator<int, Contact>
     */
    public function contacts(Connection $connection, ?DateTimeInterface $modifiedSince = null): iterable
    {
        $since = $modifiedSince === null ? null : DateTimeImmutable::createFromInterface($modifiedSince);
        $page = 1;

        do {
            $connection = $this->fresh($connection);

            $response = $this->get($connection, 'Contacts', [
                'page' => $page,
                'pageSize' => self::CONTACT_PAGE_SIZE,
                'includeArchived' => 'true',
                'order' => 'ContactID ASC',
            ], $this->modifiedSinceHeader($since));

            if ($response->status === 304) {
                return;
            }

            if ($response->failed()) {
                $this->raise($response, $connection, 'the contact list');
            }

            $rows = $response->get('Contacts', []);
            $rows = is_array($rows) ? $rows : [];

            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['ContactID'] ?? null) && $row['ContactID'] !== '') {
                    yield $this->contact($row);
                }
            }

            $pageCount = $response->get('pagination.pageCount');
            $more = is_numeric($pageCount) ? $page < (int) $pageCount : count($rows) >= self::CONTACT_PAGE_SIZE;
            $page++;
        } while ($more && $rows !== []);
    }

    /**
     * @param  array<string, mixed>  $row  One element of Xero's Contacts array.
     */
    private function contact(array $row, string $fallbackName = ''): Contact
    {
        $merged = $row['MergedToContactID'] ?? null;

        return new Contact(
            id: (string) $row['ContactID'],
            name: (string) ($row['Name'] ?? $fallbackName),
            status: isset($row['ContactStatus']) && $row['ContactStatus'] !== '' ? (string) $row['ContactStatus'] : null,
            isSupplier: (bool) ($row['IsSupplier'] ?? false),
            isCustomer: (bool) ($row['IsCustomer'] ?? false),
            // An absent, empty or all-zero id all mean the contact was never merged.
            mergedToContactId: is_string($merged) && $merged !== '' && $merged !== self::EMPTY_GUID ? $merged : null,
            updatedAt: isset($row['UpdatedDateUTC']) && is_string($row['UpdatedDateUTC']) ? XeroDate::parse($row['UpdatedDateUTC']) : null,
        );
    }

    /**
     * Refuse a write Xero would turn into different money.
     *
     * Two things Xero does on a bank transaction POST that a recode cannot argue
     * with: it defaults an omitted LineAmountTypes to Inclusive, and it ignores a
     * supplied line TaxAmount and recomputes the tax from the rate. The first is
     * handled by never omitting the mode, which needs the read to have carried one.
     * The second cannot be handled at all: a tax somebody adjusted by hand is lost
     * the moment anything is POSTed, so the only way to keep it is not to write.
     *
     * "Adjusted by hand" is detected as a line whose tax differs from what its rate
     * would produce by more than a cent. The rate comes from the tax lookup, which
     * is cached; a taxed line whose rate the lookup does not know cannot be proved
     * untouched and is refused too, with its own reason.
     *
     * @throws ValidationException with a REASON_* reason
     */
    private function refuseWhenTaxWouldMove(Connection $connection, BankTransactionData $current): void
    {
        if ($current->lineAmountType === null) {
            throw new ValidationException(
                "The bank transaction {$current->id} carries no LineAmountTypes, so a recode could not send its tax mode back and Xero would assume Inclusive.",
                $this->provider(),
                reason: ValidationException::REASON_TAX_MODE_UNKNOWN,
            );
        }

        $rates = null;

        foreach ($current->lines as $index => $line) {
            $tax = $line->taxAmount === null ? 0 : $line->taxAmount->amount;
            $lineAmount = $this->lineAmountCents($line);

            if ($current->lineAmountType === LineAmountType::NoTax || $line->taxType === null || $line->taxType === 'NONE') {
                if ($tax === 0) {
                    continue;
                }

                $this->refuseTaxOverride($current, $line, $index, 0, $tax);
            }

            if ($lineAmount === null) {
                // Neither a line amount nor a quantity and unit price: nothing to
                // compare, and nothing Xero could recompute from either.
                continue;
            }

            $rates ??= $this->taxRatesByType(
                $connection,
                array_map(static fn (BankTransactionLine $each): ?string => $each->taxType, $current->lines),
            );

            if (! array_key_exists($line->taxType, $rates)) {
                if ($tax === 0) {
                    continue;
                }

                throw new ValidationException(
                    sprintf(
                        'Line %s of the bank transaction %s is taxed as %s, which neither the active nor the archived tax rates know, so its tax cannot be proved untouched.',
                        $line->lineItemId ?? '#'.$index,
                        $current->id,
                        $line->taxType,
                    ),
                    $this->provider(),
                    reason: ValidationException::REASON_TAX_RATE_UNKNOWN,
                );
            }

            $rate = $rates[$line->taxType];

            /*
             * What Xero will compute on the write, in whole cents. Exact: a stored
             * tax a cent away from the recomputation is exactly the case a write
             * would "correct", moving the total by that cent, and the moved-money
             * check afterwards would only report what had already landed. A line
             * whose stored tax sits on a half-cent Xero rounded the other way is
             * refused with it, which is the safe side; the contract suite is the
             * place to pin Xero's rounding rule if that ever bites.
             */
            $expected = $current->lineAmountType === LineAmountType::Inclusive
                ? (int) round($lineAmount * $rate / (100 + $rate))
                : (int) round($lineAmount * $rate / 100);

            if ($expected !== $tax) {
                $this->refuseTaxOverride($current, $line, $index, $expected, $tax);
            }
        }
    }

    /**
     * The transaction's type, which the replacing write has to send back as it is,
     * and which has to be one a recode may touch at all.
     *
     * A type this build has no case for reads back as null; there is no honest
     * value to send in its place (a default of SPEND would turn a money-in line
     * into money out), so the recode is refused before any request. A recognised
     * type that is not SPEND or RECEIVE (a transfer, overpayment or prepayment
     * leg) is refused too: nothing is ever matched to one, and recoding one is
     * outside what this package does to a customer's books.
     *
     * @throws ValidationException
     */
    private function requireKnownType(BankTransactionData $current): BankTransactionType
    {
        if (! $current->type instanceof BankTransactionType) {
            throw new ValidationException(
                "The bank transaction {$current->id} is of a type this connector does not know, so a recode could not send it back unchanged.",
                $this->provider(),
                reason: ValidationException::REASON_TYPE_UNKNOWN,
            );
        }

        if (! $current->type->isMatchable()) {
            throw new ValidationException(
                "The bank transaction {$current->id} is a {$current->type->value}; only SPEND and RECEIVE transactions are recoded.",
                $this->provider(),
                reason: ValidationException::REASON_TYPE_NOT_RECODABLE,
            );
        }

        return $current->type;
    }

    /**
     * Whether a fresh read is exactly the state the change would have produced
     * from the expected one: an earlier write that landed.
     */
    private function alreadyLanded(RecodeExpectation $expectation, BankTransactionChange $change, BankTransactionData $current): bool
    {
        if (! $change->isSatisfiedBy($current)) {
            return false;
        }

        if ($change->codesAfter($expectation->accountCodesByLine) !== $current->accountCodesByLine()) {
            return false;
        }

        // The stamp must not have gone backwards; equal or missing is fine (a
        // provider or a fixture that omits it cannot be held to it).
        if ($expectation->updatedDateUtc !== null && $current->updatedDateUtc !== null
            && $current->updatedDateUtc < $expectation->updatedDateUtc) {
            return false;
        }

        return true;
    }

    /**
     * @throws ValidationException
     */
    private function refuseTaxOverride(BankTransactionData $current, BankTransactionLine $line, int $index, int $expected, int $actual): never
    {
        throw new ValidationException(
            sprintf(
                'Line %s of the bank transaction %s carries tax of %s where its rate gives %s; Xero would recompute it on any write, so the recode was refused.',
                $line->lineItemId ?? '#'.$index,
                $current->id,
                number_format($actual / 100, 2, '.', ''),
                number_format($expected / 100, 2, '.', ''),
            ),
            $this->provider(),
            reason: ValidationException::REASON_TAX_OVERRIDE_WOULD_BE_LOST,
        );
    }

    /**
     * The line's amount in cents, from the line amount or from quantity times the exact unit price.
     */
    private function lineAmountCents(BankTransactionLine $line): ?int
    {
        if ($line->lineAmount !== null) {
            return $line->lineAmount->amount;
        }

        if ($line->quantity !== null && $line->unitAmountExact !== null && is_numeric($line->unitAmountExact)) {
            return (int) round($line->quantity * (float) $line->unitAmountExact * 100);
        }

        return null;
    }

    /**
     * Tax rates by TaxType, as percentages, active ones first and archived ones on demand.
     *
     * The active lookup is what a settings page needs and is already cached. A
     * catch-up transaction is routinely coded with a rate the customer has since
     * archived, and refusing to recode exactly those lines would defeat the point,
     * so when a type is missing from the active set the archived set is fetched
     * (and cached) before the line is given up on.
     *
     * @param  array<int, string|null>  $wanted  The TaxTypes the guard needs, so the archived call is made only when one is missing.
     * @return array<string, float>
     */
    private function taxRatesByType(Connection $connection, array $wanted): array
    {
        $rates = [];

        foreach ($this->taxCodes($connection) as $code) {
            $rates[$code->reference] = $code->rate;
        }

        foreach ($wanted as $type) {
            if ($type === null || array_key_exists($type, $rates)) {
                continue;
            }

            foreach ($this->archivedTaxCodes($connection) as $code) {
                $rates[$code->reference] ??= $code->rate;
            }

            break;
        }

        return $rates;
    }

    /**
     * The rates the customer has archived, cached like the active ones.
     *
     * @return array<int, TaxCode>
     */
    private function archivedTaxCodes(Connection $connection): array
    {
        return $this->lookup($connection, 'tax_codes_archived', false, function () use ($connection): array {
            $connection = $this->fresh($connection);
            $response = $this->get($connection, 'TaxRates', ['where' => 'Status=="ARCHIVED"']);

            if ($response->failed()) {
                $this->raise($response, $connection, 'the archived tax rate lookup');
            }

            $codes = [];

            foreach ($this->rowsOf($response->get('TaxRates', [])) as $rate) {
                $codes[] = new TaxCode(
                    reference: (string) ($rate['TaxType'] ?? ''),
                    name: (string) ($rate['Name'] ?? ''),
                    rate: (float) ($rate['EffectiveRate'] ?? $rate['DisplayTaxRate'] ?? 0),
                );
            }

            return $codes;
        }, fn (array $row): TaxCode => TaxCode::fromArray($row));
    }

    /**
     * The array rows of a provider list, skipping anything that is not one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(mixed $rows): array
    {
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The full replacement body for a transaction whose coding is changing.
     *
     * Everything the read carried goes back, because a POST replaces the transaction
     * and Xero fills what is missing with defaults that move money: an omitted
     * LineAmountTypes is read as Inclusive, an omitted CurrencyRate is recomputed
     * from the day's rate, an omitted ItemCode is cleared. The tax mode is never
     * defaulted here; a read without one was refused before this runs.
     *
     * @return array<string, mixed>
     */
    private function recodedBody(BankTransactionData $current, BankTransactionChange $change, BankTransactionType $type): array
    {
        $body = [
            'BankTransactionID' => $current->id,
            'Type' => $type->value,
            'LineAmountTypes' => ($current->lineAmountType ?? LineAmountType::Inclusive)->toXero(),
            'LineItems' => array_map(
                fn (BankTransactionLine $line): array => $this->recodedLine($line, $change->codings),
                $current->lines,
            ),
        ];

        if ($current->bankAccountId !== null) {
            $body['BankAccount'] = ['AccountID' => $current->bankAccountId];
        }

        $contactId = $change->contactId ?? $current->contactId;

        if ($contactId !== null && $contactId !== '') {
            $body['Contact'] = ['ContactID' => $contactId];
        }

        if ($current->date !== null) {
            $body['Date'] = XeroDate::toXero($current->date);
        }

        if ($current->reference !== null) {
            $body['Reference'] = $current->reference;
        }

        if ($current->status !== null) {
            $body['Status'] = $current->status;
        }

        if ($current->currency !== null) {
            $body['CurrencyCode'] = $current->currency;
        }

        if ($current->currencyRate !== null) {
            $body['CurrencyRate'] = $current->currencyRate;
        }

        return $body;
    }

    /**
     * One line, with the coding that applies to it laid over what is already there.
     *
     * @param  array<int, LineCoding>  $codings
     * @return array<string, mixed>
     */
    private function recodedLine(BankTransactionLine $line, array $codings): array
    {
        $accountCode = $line->accountCode;
        $tracking = $line->tracking;

        foreach ($codings as $coding) {
            if (! $coding->appliesTo($line->lineItemId)) {
                continue;
            }

            if ($coding->accountCode !== null) {
                $accountCode = $coding->accountCode;
            }

            if ($coding->tracking !== null) {
                $tracking = $coding->tracking;
            }
        }

        $body = [];

        if ($line->lineItemId !== null) {
            $body['LineItemID'] = $line->lineItemId;
        }

        if ($line->description !== null) {
            $body['Description'] = $line->description;
        }

        /*
         * Amounts go back exactly as they came. Xero recomputes LineAmount from
         * Quantity times UnitAmount when both are present, so the unit price goes
         * back at the precision it was read at (four places, see the reads), not
         * rounded to cents; a price rounded to cents times a quantity is a different
         * line. TaxAmount is deliberately absent: Xero ignores it on this endpoint,
         * and a line whose tax it would recompute differently was refused earlier.
         */
        if ($line->quantity !== null) {
            $body['Quantity'] = $line->quantity;
        }

        if ($line->unitAmountExact !== null && is_numeric($line->unitAmountExact)) {
            $body['UnitAmount'] = (float) $line->unitAmountExact;
        } elseif ($line->unitAmount !== null) {
            $body['UnitAmount'] = $line->unitAmount->toDecimal();
        }

        if ($line->lineAmount !== null) {
            $body['LineAmount'] = $line->lineAmount->toDecimal();
        }

        if ($line->taxType !== null) {
            $body['TaxType'] = $line->taxType;
        }

        if ($line->itemCode !== null) {
            $body['ItemCode'] = $line->itemCode;
        }

        if ($accountCode !== null && $accountCode !== '') {
            $body['AccountCode'] = $accountCode;
        }

        $body['Tracking'] = array_values(array_map(static fn (TrackingRef $ref): array => [
            'TrackingCategoryID' => $ref->categoryId,
            'TrackingOptionID' => $ref->optionId,
        ], $tracking));

        return $body;
    }

    /**
     * Xero's `where` expression for a bank transaction query, or null for no filter.
     *
     * Dates are sent as DateTime(y,m,d) rather than quoted strings: Xero parses a
     * quoted date in the connected company's own locale, so an unqualified "03/04"
     * silently means March in one company and April in another.
     */
    private function bankTransactionWhere(BankTransactionQuery $query): ?string
    {
        $clauses = [];

        if ($query->type !== null) {
            $clauses[] = 'Type=="'.$query->type->value.'"';
        }

        if ($query->status !== null && $query->status !== '') {
            $clauses[] = 'Status=="'.strtoupper($query->status).'"';
        }

        if ($query->from !== null) {
            $clauses[] = 'Date>='.$this->whereDate($query->from);
        }

        if ($query->to !== null) {
            $clauses[] = 'Date<='.$this->whereDate($query->to);
        }

        if ($query->bankAccountId !== null && $query->bankAccountId !== '') {
            $clauses[] = 'BankAccount.AccountID==Guid("'.$query->bankAccountId.'")';
        }

        return $clauses === [] ? null : implode('&&', $clauses);
    }

    private function whereDate(DateTimeInterface $date): string
    {
        return 'DateTime('.$date->format('Y').','.$date->format('n').','.$date->format('j').')';
    }

    /**
     * @return array<string, string>
     */
    private function modifiedSinceHeader(?DateTimeImmutable $since): array
    {
        if ($since === null) {
            return [];
        }

        // Xero compares this against UpdatedDateUTC, so it has to be sent in UTC or a
        // host in a positive offset asks for the future and gets nothing back.
        return ['If-Modified-Since' => XeroDate::toXeroDateTime(
            $since->setTimezone(new DateTimeZone('UTC')),
        )];
    }

    /**
     * One BankTransaction from the wire.
     *
     * @param  array<string, mixed>  $row
     */
    private function bankTransaction(array $row): BankTransactionData
    {
        $contact = is_array($row['Contact'] ?? null) ? $row['Contact'] : [];
        $bankAccount = is_array($row['BankAccount'] ?? null) ? $row['BankAccount'] : [];

        $money = static fn (mixed $value): ?Money => is_numeric($value)
            ? Money::fromDecimal((float) $value)
            : null;

        $lines = [];

        foreach (is_array($row['LineItems'] ?? null) ? $row['LineItems'] : [] as $line) {
            if (is_array($line)) {
                $lines[] = $this->bankTransactionLine($line, $money);
            }
        }

        $string = static fn (mixed $value): ?string => is_scalar($value) && (string) $value !== ''
            ? (string) $value
            : null;

        return new BankTransactionData(
            id: (string) ($row['BankTransactionID'] ?? ''),
            type: BankTransactionType::tryFromXero($string($row['Type'] ?? null)),
            date: XeroDate::parse($string($row['Date'] ?? null)),
            total: $money($row['Total'] ?? null) ?? Money::zero(),
            subTotal: $money($row['SubTotal'] ?? null),
            totalTax: $money($row['TotalTax'] ?? null),
            currency: $string($row['CurrencyCode'] ?? null),
            status: $string($row['Status'] ?? null),
            contactId: $string($contact['ContactID'] ?? null),
            contactName: $string($contact['Name'] ?? null),
            bankAccountId: $string($bankAccount['AccountID'] ?? null),
            bankAccountName: $string($bankAccount['Name'] ?? null),
            reference: $string($row['Reference'] ?? null),
            isReconciled: (bool) ($row['IsReconciled'] ?? false),
            hasAttachments: (bool) ($row['HasAttachments'] ?? false),
            lines: $lines,
            updatedDateUtc: XeroDate::parse($string($row['UpdatedDateUTC'] ?? null)),
            lineAmountType: LineAmountType::fromXero($string($row['LineAmountTypes'] ?? null)),
            currencyRate: is_numeric($row['CurrencyRate'] ?? null) ? (float) $row['CurrencyRate'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  callable(mixed): (Money|null)  $money
     */
    private function bankTransactionLine(array $line, callable $money): BankTransactionLine
    {
        $tracking = [];

        foreach (is_array($line['Tracking'] ?? null) ? $line['Tracking'] : [] as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $categoryId = (string) ($ref['TrackingCategoryID'] ?? '');
            $optionId = (string) ($ref['TrackingOptionID'] ?? '');

            if ($categoryId === '' || $optionId === '') {
                continue;
            }

            $tracking[] = new TrackingRef(
                categoryId: $categoryId,
                optionId: $optionId,
                categoryName: isset($ref['Name']) ? (string) $ref['Name'] : null,
                optionName: isset($ref['Option']) ? (string) $ref['Option'] : null,
            );
        }

        $string = static fn (mixed $value): ?string => is_scalar($value) && (string) $value !== ''
            ? (string) $value
            : null;

        return new BankTransactionLine(
            lineItemId: $string($line['LineItemID'] ?? null),
            description: $string($line['Description'] ?? null),
            quantity: isset($line['Quantity']) && is_numeric($line['Quantity']) ? (float) $line['Quantity'] : null,
            unitAmount: $money($line['UnitAmount'] ?? null),
            lineAmount: $money($line['LineAmount'] ?? null),
            accountCode: $string($line['AccountCode'] ?? null),
            accountId: $string($line['AccountID'] ?? null),
            taxType: $string($line['TaxType'] ?? null),
            tracking: $tracking,
            // The wire figure verbatim, so a four-place unit price survives the
            // round trip that Money, in cents, cannot carry.
            unitAmountExact: is_numeric($line['UnitAmount'] ?? null) ? $this->decimalString($line['UnitAmount']) : null,
            taxAmount: $money($line['TaxAmount'] ?? null),
            itemCode: $string($line['ItemCode'] ?? null),
        );
    }

    /**
     * A JSON number as a decimal string with no exponent and no float noise.
     */
    private function decimalString(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Four places is the most Xero stores for a unit amount; trailing zeros
            // go so 42.5000 and 42.5 read as the same figure.
            $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

            return $formatted === '' || $formatted === '-' ? '0' : $formatted;
        }

        return (string) $value;
    }

    private function mapper(): XeroPayloadMapper
    {
        // Lazily built so the constructor signature stays inherited and callers do
        // not have to know the mapper exists.
        return $this->mapper ??= new XeroPayloadMapper;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers  Merged over the standard set.
     */
    private function get(Connection $connection, string $resource, array $query = [], array $headers = []): HttpResponse
    {
        $url = self::API_BASE.'/'.$resource;

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->http->send('GET', $url, $headers + $this->headers($connection), null, $this->provider(), $connection->tenantId);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    private function post(Connection $connection, string $resource, array $body, ?string $idempotencyKey = null, array $query = []): HttpResponse
    {
        $headers = $this->headers($connection) + ['Content-Type' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $url = self::API_BASE.'/'.$resource;

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->http->send(
            'POST',
            $url,
            $headers,
            json_encode($body, JSON_THROW_ON_ERROR),
            $this->provider(),
            $connection->tenantId,
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
