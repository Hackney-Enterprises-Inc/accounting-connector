<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Connectors\QuickBooks;

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
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\PaymentData;
use Hei\AccountingConnector\Data\RawPayload;
use Hei\AccountingConnector\Data\SalesItem;
use Hei\AccountingConnector\Data\TaxCode;
use Hei\AccountingConnector\Data\TenantInfo;
use Hei\AccountingConnector\Data\TokenSet;
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
 * QuickBooks Online, over the Intuit Accounting API v3.
 *
 * Two Intuit behaviours drive the design here.
 *
 * The refresh token rotates on every single refresh. Miss one write and the
 * connection is dead, so refresh() persists through the ConnectionStore before the
 * new access token is used for anything.
 *
 * Idempotency is a `requestid` query parameter capped at 50 characters, not a
 * header, and a duplicate does not error: Intuit quietly replays the original
 * response. That makes an accidental key collision invisible, which is why
 * Support\IdempotencyKey hashes rather than truncates.
 */
final class QuickBooksConnector extends AbstractConnector
{
    public const AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';

    public const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public const REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    public const PRODUCTION_BASE = 'https://quickbooks.api.intuit.com';

    public const SANDBOX_BASE = 'https://sandbox-quickbooks.api.intuit.com';

    public const DEFAULT_SCOPE = 'com.intuit.quickbooks.accounting';

    /**
     * Intuit accepts far larger attachments than Xero, but a 100 MB upload from a
     * queue worker is its own kind of problem. Capped at 20 MB, which is comfortably
     * above any receipt and below anything that will time out a worker.
     */
    public const ATTACHMENT_LIMIT_BYTES = 20 * 1024 * 1024;

    private ?QuickBooksPayloadMapper $mapper = null;

    private string $baseUrl = self::PRODUCTION_BASE;

    private string $minorVersion = '75';

    /**
     * Point at the Intuit sandbox. OAuth endpoints are unchanged; only the API host moves.
     */
    public function usingBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        return $this;
    }

    /**
     * Intuit versions its API by a minor-version query parameter rather than a path.
     * Fields appear and change meaning between versions, so pin it deliberately.
     */
    public function usingMinorVersion(string $minorVersion): self
    {
        $this->minorVersion = $minorVersion;

        return $this;
    }

    public function provider(): Provider
    {
        return Provider::QuickBooksOnline;
    }

    public function supports(EntityType $type): bool
    {
        return true;
    }

    public function attachmentSizeLimit(): int
    {
        return self::ATTACHMENT_LIMIT_BYTES;
    }

    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'scope' => self::DEFAULT_SCOPE,
            'redirect_uri' => $redirectUri ?? $this->redirectUri,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, array $parameters = [], ?string $redirectUri = null): AuthorizationResult
    {
        // Intuit delivers the company id as a `realmId` query parameter on the
        // callback, not in the token response, so the caller has to pass it through.
        $realmId = $parameters['realmId'] ?? $parameters['realm_id'] ?? null;

        $response = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri ?? $this->redirectUri,
        ]);

        if ($response->failed()) {
            throw new AuthenticationException(
                'Intuit refused the authorisation code: '.$this->describeError($response),
                $this->provider(),
                $this->describeError($response),
            );
        }

        return new AuthorizationResult(
            provider: $this->provider(),
            tokens: TokenSet::fromResponse($response->json()),
            tenantId: is_scalar($realmId) ? (string) $realmId : null,
        );
    }

    public function revoke(Connection $connection): bool
    {
        $token = $connection->refreshToken ?? $connection->accessToken;

        try {
            $response = $this->http->send(
                'POST',
                self::REVOKE_URL,
                [
                    'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                json_encode(['token' => $token], JSON_THROW_ON_ERROR),
                $this->provider(),
            );

            return $response->successful();
        } catch (\Throwable $e) {
            // Swallowed on purpose: the local disconnect has to happen either way.
            $this->logger->warning('Could not revoke the QuickBooks connection at Intuit.', [
                'connection' => $connection->reference,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function tenantInfo(Connection $connection): ?TenantInfo
    {
        $connection = $this->fresh($connection);

        $response = $this->get($connection, 'companyinfo/'.$connection->tenantId);

        if ($response->failed()) {
            return null;
        }

        $company = $response->get('CompanyInfo');

        if (! is_array($company)) {
            return null;
        }

        return new TenantInfo(
            id: $connection->tenantId,
            name: (string) ($company['CompanyName'] ?? ''),
            legalName: isset($company['LegalName']) ? (string) $company['LegalName'] : null,
            countryCode: isset($company['Country']) ? (string) $company['Country'] : null,
            currencyCode: isset($company['Currency']['value']) ? (string) $company['Currency']['value'] : null,
        );
    }

    public function chartOfAccounts(Connection $connection, bool $forceRefresh = false): array
    {
        return $this->lookup(
            $connection,
            self::LOOKUP_CHART_OF_ACCOUNTS,
            $forceRefresh,
            // 1000 is Intuit's page ceiling. A company with more active accounts
            // than that would need startposition paging; none of ours is close, so
            // the ceiling is documented rather than paged. Same below for tax codes.
            fn (): array => $this->fetchAccounts(
                $connection,
                'select * from Account where Active = true maxresults 1000',
            ),
            fn (array $row): Account => Account::fromArray($row),
        );
    }

    public function bankAccounts(Connection $connection, bool $forceRefresh = false): array
    {
        return Account::only($this->chartOfAccounts($connection, $forceRefresh), AccountClass::Bank);
    }

    /**
     * Always empty. QuickBooks has no tracking-category equivalent.
     *
     * Returning nothing rather than throwing means a host can render one tracking
     * dropdown for both providers and let it come back empty, instead of branching
     * on the provider everywhere it touches the UI.
     */
    public function trackingCategories(Connection $connection, bool $forceRefresh = false): array
    {
        return [];
    }

    public function taxCodes(Connection $connection, bool $forceRefresh = false): array
    {
        return $this->lookup($connection, 'tax_codes', $forceRefresh, function () use ($connection): array {
            $result = $this->query($connection, 'select * from TaxCode where Active = true maxresults 1000');

            $codes = [];

            foreach ($result['TaxCode'] ?? [] as $code) {
                if (! is_array($code)) {
                    continue;
                }

                $codes[] = new TaxCode(
                    reference: (string) ($code['Id'] ?? ''),
                    name: (string) ($code['Name'] ?? ''),
                    rate: 0.0,
                    isSalesTax: (bool) ($code['Taxable'] ?? false),
                    isPurchaseTax: (bool) ($code['Taxable'] ?? false),
                );
            }

            usort($codes, fn (TaxCode $a, TaxCode $b): int => strcasecmp($a->name, $b->name));

            return $codes;
        }, fn (array $row): TaxCode => TaxCode::fromArray($row));
    }

    /**
     * Resolve a contact to its QuickBooks id, creating it when absent.
     *
     * Checks the entity map first, exactly as the Xero connector does.
     */
    public function resolveContact(ContactData $contact, Connection $connection): string
    {
        $connection = $this->fresh($connection);
        $resource = $contact->role === EntityType::Customer ? 'Customer' : 'Vendor';
        $mapKey = $contact->mapKey();

        $known = $this->entityMap->externalId($connection, $contact->role, $mapKey);

        if ($known !== null) {
            return $known;
        }

        // A single quote terminates the string literal in Intuit's query language.
        // The documented escape is a backslash, which is what AccountingPipe does.
        $escaped = str_replace("'", "\\'", $contact->name);

        try {
            $found = $this->query($connection, "select * from {$resource} where DisplayName = '{$escaped}'");
            $existing = $found[$resource][0]['Id'] ?? null;

            if ($existing !== null) {
                $this->entityMap->remember($connection, $contact->role, $mapKey, (string) $existing);

                return (string) $existing;
            }
        } catch (AccountingConnectorException $e) {
            // A failed lookup is not a failed create. Fall through and try to create;
            // if the contact really does exist, Intuit says so and that is a clearer
            // error than the lookup's.
            $this->logger->warning('QuickBooks contact lookup failed; attempting to create instead.', [
                'connection' => $connection->reference,
                'error' => $e->getMessage(),
            ]);
        }

        $created = $this->post($connection, strtolower($resource), $this->mapper()->contact($contact));

        if ($created->failed()) {
            $this->raise($created, $connection, "creating the {$resource} '{$contact->name}'");
        }

        $id = $created->get($resource.'.Id');

        if ($id === null) {
            throw new ValidationException(
                "QuickBooks accepted the {$resource} '{$contact->name}' but returned no id.",
                $this->provider(),
            );
        }

        $this->entityMap->remember($connection, $contact->role, $mapKey, (string) $id);

        return (string) $id;
    }

    /**
     * Run a statement against the connected company's realm.
     *
     * @return array<string, mixed> The QueryResponse payload.
     */
    public function query(Connection $connection, string $statement): array
    {
        $response = $this->get($connection, 'query', ['query' => $statement]);

        if ($response->failed()) {
            $this->raise($response, $connection, 'a QuickBooks query');
        }

        $result = $response->get('QueryResponse');

        return is_array($result) ? $result : [];
    }

    protected function requestRefresh(Connection $connection): TokenSet
    {
        $response = $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $connection->refreshToken,
        ]);

        if ($response->failed()) {
            $reason = $this->describeError($response);

            if ($response->status === 400 || $response->status === 401) {
                $this->announceRevocation($connection, $reason);

                throw ConnectionRevokedException::for($connection, $reason);
            }

            $this->raise($response, $connection, 'the token refresh');
        }

        $payload = $response->json();

        if (empty($payload['access_token'])) {
            throw new AuthenticationException(
                'Intuit returned a successful refresh with no access token in it.',
                $this->provider(),
            );
        }

        // Intuit rotates the refresh token on every refresh, but does not always
        // include it. Carry the old one forward rather than blanking the field.
        $payload['refresh_token'] ??= $connection->refreshToken;

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

                return $this->createAndRead(
                    $connection,
                    'bill',
                    'Bill',
                    $this->mapper()->bill($bill, $this->resolveContact($bill->vendorContact(), $connection)),
                    $idempotencyKey,
                );

            case EntityType::Expense:
                /** @var ExpenseData $expense */
                $expense = $this->as($payload, ExpenseData::class);
                $this->assertPaymentType($expense->paymentMethod);
                $this->assertMoneyOut($expense);

                $account = $expense->bankAccount ?? $connection->setting('bank_account');

                if (! is_string($account) || $account === '') {
                    throw new InvalidPayloadException(
                        'A QuickBooks purchase needs the account the money left. Set one on the '
                        .'ExpenseData or as the "bank_account" connection setting.',
                        $this->provider(),
                    );
                }

                return $this->createAndRead(
                    $connection,
                    'purchase',
                    'Purchase',
                    $this->mapper()->expense($expense, $this->resolveContact($expense->vendorContact(), $connection), $account),
                    $idempotencyKey,
                );

            case EntityType::Invoice:
                /** @var InvoiceData $invoice */
                $invoice = $this->as($payload, InvoiceData::class);
                $customerId = $this->resolveContact($invoice->customerContact(), $connection);

                return $this->createAndRead(
                    $connection,
                    'invoice',
                    'Invoice',
                    $this->mapper()->invoice($invoice, $customerId, $this->itemIdsFor($invoice->lines, $connection)),
                    $idempotencyKey,
                );

            case EntityType::Payment:
                /** @var PaymentData $payment */
                $payment = $this->as($payload, PaymentData::class);
                $customer = $payment->customerContact();

                if ($customer === null) {
                    // Xero attaches a payment to an invoice alone; QuickBooks needs the
                    // customer as well, and refuses the post without one.
                    throw new InvalidPayloadException(
                        'A QuickBooks payment needs a customer as well as an invoice id.',
                        $this->provider(),
                    );
                }

                $deposit = $payment->account ?? $connection->setting('bank_account');

                return $this->createAndRead(
                    $connection,
                    'payment',
                    'Payment',
                    $this->mapper()->payment(
                        $payment,
                        $this->resolveContact($customer, $connection),
                        is_string($deposit) && $deposit !== '' ? $deposit : null,
                    ),
                    $idempotencyKey,
                );

            case EntityType::Journal:
                /** @var JournalData $journal */
                $journal = $this->as($payload, JournalData::class);

                return $this->createAndRead(
                    $connection,
                    'journalentry',
                    'JournalEntry',
                    $this->mapper()->journal($journal),
                    $idempotencyKey,
                );

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
        [$path, $key] = $this->resourceFor($type);

        // What can be refused from the payload alone is refused before the read
        // below, so a refund never costs a request.
        if ($type === EntityType::Expense && $payload instanceof ExpenseData) {
            $this->assertMoneyOut($payload);
        }

        // Intuit's update is a full replace and demands the current SyncToken as an
        // optimistic lock. Reading it first is not optional: a stale token is a 400,
        // and guessing zero silently clobbers a concurrent edit.
        $current = $this->get($connection, $path.'/'.$externalId);

        if ($current->failed()) {
            $this->raise($current, $connection, "reading {$key} {$externalId} before updating it");
        }

        $syncToken = $current->get($key.'.SyncToken');

        $body = $payload instanceof RawPayload
            ? $payload->body
            : $this->bodyForUpdate($type, $payload, $connection);

        $body['Id'] = $externalId;
        $body['SyncToken'] = (string) ($syncToken ?? '0');
        $body['sparse'] = false;

        $response = $this->post($connection, $path, $body, null, ['operation' => 'update']);

        if ($response->failed()) {
            $this->raise($response, $connection, "updating {$key} {$externalId}");
        }

        return true;
    }

    protected function performAttach(
        EntityType $type,
        string $externalId,
        Attachment $attachment,
        Connection $connection,
    ): AttachmentResult {
        $filename = Filename::sanitise($attachment->normalisedFilename());

        $metadata = json_encode([
            'AttachableRef' => [[
                'EntityRef' => ['type' => $this->attachableTypeFor($type), 'value' => $externalId],
            ]],
            'FileName' => $filename,
            'ContentType' => $attachment->mimeType,
        ], JSON_THROW_ON_ERROR);

        // Intuit's upload endpoint is multipart with two specifically named parts.
        // The names are literal and the suffix pairs them; renaming either is a 400.
        $boundary = 'apc'.bin2hex(random_bytes(16));
        $body = $this->multipart($boundary, [
            ['name' => 'file_metadata_01', 'filename' => 'metadata.json', 'type' => 'application/json', 'contents' => $metadata],
            ['name' => 'file_content_01', 'filename' => $filename, 'type' => $attachment->mimeType, 'contents' => $attachment->contents],
        ]);

        $response = $this->http->send(
            'POST',
            $this->url($connection, 'upload'),
            [
                'Authorization' => 'Bearer '.$connection->accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'multipart/form-data; boundary='.$boundary,
            ],
            $body,
            $this->provider(),
            $connection->tenantId,
        );

        if ($response->failed()) {
            return AttachmentResult::failed($this->describeError($response), $filename);
        }

        $attachableId = $response->get('AttachableResponse.0.Attachable.Id');

        return AttachmentResult::success(
            filename: $filename,
            bytes: $attachment->size(),
            externalId: $attachableId === null ? null : (string) $attachableId,
        );
    }

    protected function describeError(HttpResponse $response): string
    {
        $itemised = $this->describeErrors($response);

        if ($itemised !== []) {
            return implode('; ', $itemised);
        }

        $body = $response->json();

        if (isset($body['error']) && is_string($body['error'])) {
            $description = isset($body['error_description']) && is_string($body['error_description'])
                ? ': '.$body['error_description']
                : '';

            return $body['error'].$description;
        }

        return 'HTTP '.$response->status;
    }

    protected function describeErrors(HttpResponse $response): array
    {
        // Deliberately reads only the Fault, never the request that caused it. A bill
        // payload carries vendor names and amounts, and a token request carries a
        // secret; neither belongs anywhere near a log line.
        $errors = $response->get('Fault.Error', []);
        $messages = [];

        foreach (is_array($errors) ? $errors : [] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = trim(
                ((string) ($error['Message'] ?? 'QuickBooks error')).': '.((string) ($error['Detail'] ?? '')),
                ': ',
            );

            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Post an entity and read its id back out of the response.
     *
     * @param  array<string, mixed>  $body
     */
    private function createAndRead(
        Connection $connection,
        string $path,
        string $key,
        array $body,
        ?string $idempotencyKey,
    ): ?string {
        $response = $this->post($connection, $path, $body, $idempotencyKey);

        if ($response->failed()) {
            $this->raise($response, $connection, "creating the {$key}");
        }

        $id = $response->get($key.'.Id');

        return $id === null ? null : (string) $id;
    }

    private function createRaw(EntityType $type, RawPayload $payload, Connection $connection, ?string $idempotencyKey): ?string
    {
        [$path, $key] = $this->resourceFor($type);

        $body = $payload->body;

        // A raw payload may name a vendor rather than reference one, which is exactly
        // what AccountingPipe's existing QuickBooksMapper emits. Resolve it so a
        // straight port of that code keeps working.
        if (isset($body['VendorName'])) {
            $vendorName = (string) $body['VendorName'];
            unset($body['VendorName']);

            $vendorId = $this->resolveContact(ContactData::vendor($vendorName), $connection);

            if ($type === EntityType::Bill) {
                $body['VendorRef'] = ['value' => $vendorId, 'name' => $vendorName];
            } else {
                $body['EntityRef'] = ['value' => $vendorId, 'name' => $vendorName, 'type' => 'Vendor'];
            }
        }

        return $this->createAndRead($connection, $path, $key, $body, $idempotencyKey);
    }

    /**
     * @return array<int, Account>
     */
    private function fetchAccounts(Connection $connection, string $statement): array
    {
        $result = $this->query($connection, $statement);

        $accounts = [];

        foreach ($result['Account'] ?? [] as $account) {
            if (! is_array($account)) {
                continue;
            }

            $id = (string) ($account['Id'] ?? '');

            $type = isset($account['AccountType']) ? (string) $account['AccountType'] : null;

            $accounts[] = new Account(
                id: $id,
                name: (string) ($account['Name'] ?? ''),
                code: isset($account['AcctNum']) ? (string) $account['AcctNum'] : null,
                type: $type,
                class: AccountClass::fromQuickBooks($type),
                // Always the id. QuickBooks line items never address an account by its
                // number, even when the company has assigned one.
                reference: $id,
                currency: isset($account['CurrencyRef']['value']) ? (string) $account['CurrencyRef']['value'] : null,
            );
        }

        usort($accounts, fn (Account $a, Account $b): int => strcasecmp($a->name, $b->name));

        return $accounts;
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

                return $this->mapper()->invoice(
                    $invoice,
                    $this->resolveContact($invoice->customerContact(), $connection),
                    $this->itemIdsFor($invoice->lines, $connection),
                );

            case EntityType::Expense:
                /** @var ExpenseData $expense */
                $expense = $this->as($payload, ExpenseData::class);
                $this->assertPaymentType($expense->paymentMethod);
                $this->assertMoneyOut($expense);
                $account = $expense->bankAccount ?? $connection->setting('bank_account');

                if (! is_string($account) || $account === '') {
                    throw new InvalidPayloadException(
                        'A QuickBooks purchase needs the account the money left.',
                        $this->provider(),
                    );
                }

                return $this->mapper()->expense(
                    $expense,
                    $this->resolveContact($expense->vendorContact(), $connection),
                    $account,
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
                    'Updating a QuickBooks payment is not supported. Void it and post a new one.',
                    $this->provider(),
                );

            default:
                throw UnsupportedEntityTypeException::for($this->provider(), $type);
        }
    }

    public function refreshLookups(Connection $connection): void
    {
        parent::refreshLookups($connection);
        $this->items($connection, forceRefresh: true);
    }

    /**
     * The product/service items on the connected company, cached per connection.
     *
     * @return array<int, SalesItem>
     */
    private function items(Connection $connection, bool $forceRefresh = false): array
    {
        return $this->lookup(
            $connection,
            'items',
            $forceRefresh,
            fn (): array => $this->fetchItems($connection),
            fn (array $row): SalesItem => SalesItem::fromArray($row),
        );
    }

    /**
     * @return array<int, SalesItem>
     */
    private function fetchItems(Connection $connection): array
    {
        $result = $this->query($connection, 'select * from Item where Active = true maxresults 1000');

        $items = [];

        foreach ($result['Item'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = new SalesItem(
                id: (string) ($item['Id'] ?? ''),
                name: (string) ($item['Name'] ?? ''),
                incomeAccountId: isset($item['IncomeAccountRef']['value'])
                    ? (string) $item['IncomeAccountRef']['value']
                    : null,
            );
        }

        usort($items, fn (SalesItem $a, SalesItem $b): int => strcasecmp($a->name, $b->name));

        return $items;
    }

    /**
     * Item id per income account, from the cached item list.
     *
     * @return array<string, string>
     */
    private function itemsByAccount(Connection $connection, bool $forceRefresh = false): array
    {
        $map = [];

        foreach ($this->items($connection, $forceRefresh) as $item) {
            if ($item->incomeAccountId !== null && ! isset($map[$item->incomeAccountId])) {
                $map[$item->incomeAccountId] = $item->id;
            }
        }

        return $map;
    }

    /**
     * The QBO item each invoice line should carry, resolved per income account.
     *
     * QuickBooks sales lines are item-based: ItemAccountRef is ignored on create
     * (verified live — the line quietly attaches to the company's default item and
     * posts income to THAT item's account), so a line addressed at an income
     * account must carry an ItemRef wired to it. Missing items are created as
     * Service items named after the account.
     *
     * @param  array<int, LineItem>  $lines
     * @return array<int, string|null> One entry per line; null lets the company default apply.
     */
    private function itemIdsFor(array $lines, Connection $connection): array
    {
        $wanted = [];

        foreach ($lines as $line) {
            if ($line->accountCode !== null) {
                $wanted[$line->accountCode] = true;
            }
        }

        $resolved = [];

        if ($wanted !== []) {
            $byAccount = $this->itemsByAccount($connection);

            foreach (array_keys($wanted) as $accountId) {
                $accountId = (string) $accountId;
                $resolved[$accountId] = $byAccount[$accountId]
                    ?? $this->createItemForAccount($accountId, $connection);
            }
        }

        return array_map(
            fn (LineItem $line): ?string => $line->accountCode !== null ? $resolved[$line->accountCode] : null,
            $lines,
        );
    }

    /**
     * Create a Service item wired to an income account, named after the account.
     *
     * Item names are unique per company. A 6240 duplicate-name fault means the name
     * serves a different account (or a racing worker just created it), so the list
     * is rescanned and a suffixed name tried before giving up.
     */
    private function createItemForAccount(string $accountId, Connection $connection): string
    {
        $accountName = null;

        foreach ($this->chartOfAccounts($connection) as $account) {
            if ($account->id === $accountId) {
                $accountName = $account->name;

                break;
            }
        }

        $base = $accountName ?? "Income account {$accountId}";

        foreach ([$base, "{$base} ({$accountId})"] as $name) {
            $response = $this->post($connection, 'item', [
                'Name' => $name,
                'Type' => 'Service',
                'IncomeAccountRef' => ['value' => $accountId],
            ]);

            if ($response->successful()) {
                $id = $response->get('Item.Id');

                if ($id !== null) {
                    // Write through, so the next process's snapshot carries it.
                    $this->items($connection, forceRefresh: true);

                    return (string) $id;
                }
            }

            if ((string) $response->get('Fault.Error.0.code') === '6240') {
                $found = $this->itemsByAccount($connection, forceRefresh: true)[$accountId] ?? null;

                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            $this->raise($response, $connection, "creating the item '{$name}'");
        }

        throw new ValidationException(
            "Could not create a QuickBooks item for income account {$accountId}: both candidate names are taken.",
            $this->provider(),
        );
    }

    /**
     * Refuse a PaymentType Intuit does not recognise, before the round trip.
     *
     * Intuit's own refusal for a bad PaymentType is a generic validation fault that
     * does not name the field or the allowed values.
     */
    /**
     * A Purchase is money out by definition. Posting a refund as one, on a create
     * or on an update, would record the money leaving twice; refusing is the
     * honest answer until a Deposit mapping exists.
     *
     * @throws InvalidPayloadException
     */
    private function assertMoneyOut(ExpenseData $expense): void
    {
        if ($expense->direction->isIn()) {
            throw new InvalidPayloadException(
                'QuickBooks has no money-in purchase; a RECEIVE expense cannot be posted here.',
                $this->provider(),
            );
        }
    }

    private function assertPaymentType(?string $method): void
    {
        if ($method !== null && ! in_array($method, ['Cash', 'Check', 'CreditCard'], true)) {
            throw new InvalidPayloadException(sprintf(
                'QuickBooks PaymentType must be Cash, Check or CreditCard, got "%s".',
                $method,
            ), $this->provider());
        }
    }

    /**
     * The Intuit resource path and its response key for a canonical entity type.
     *
     * @return array{0: string, 1: string}
     */
    private function resourceFor(EntityType $type): array
    {
        return match ($type) {
            EntityType::Bill => ['bill', 'Bill'],
            EntityType::Expense => ['purchase', 'Purchase'],
            EntityType::Invoice => ['invoice', 'Invoice'],
            EntityType::Payment => ['payment', 'Payment'],
            EntityType::Journal => ['journalentry', 'JournalEntry'],
            EntityType::Customer => ['customer', 'Customer'],
            EntityType::Vendor => ['vendor', 'Vendor'],
        };
    }

    /**
     * What Intuit's Attachable calls this entity.
     */
    private function attachableTypeFor(EntityType $type): string
    {
        return $this->resourceFor($type)[1];
    }

    /**
     * @param  array<string, string>  $form
     */
    private function token(array $form): HttpResponse
    {
        return $this->http->send(
            'POST',
            self::TOKEN_URL,
            [
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            http_build_query($form),
            $this->provider(),
        );
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(Connection $connection, string $path, array $query = []): HttpResponse
    {
        return $this->http->send(
            'GET',
            $this->url($connection, $path, $query),
            [
                'Authorization' => 'Bearer '.$connection->accessToken,
                'Accept' => 'application/json',
            ],
            null,
            $this->provider(),
            $connection->tenantId,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $query
     */
    private function post(
        Connection $connection,
        string $path,
        array $body,
        ?string $idempotencyKey = null,
        array $query = [],
    ): HttpResponse {
        if ($idempotencyKey !== null) {
            // Intuit takes idempotency as a query parameter rather than a header.
            $query['requestid'] = $idempotencyKey;
        }

        return $this->http->send(
            'POST',
            $this->url($connection, $path, $query),
            [
                'Authorization' => 'Bearer '.$connection->accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            json_encode($body, JSON_THROW_ON_ERROR),
            $this->provider(),
            $connection->tenantId,
        );
    }

    /**
     * Build a URL inside the connection's own realm.
     *
     * The realm always comes from the Connection and is never taken from a request.
     * That is what keeps one customer's books out of another's.
     *
     * @param  array<string, string>  $query
     */
    private function url(Connection $connection, string $path, array $query = []): string
    {
        $query['minorversion'] = $this->minorVersion;

        return sprintf(
            '%s/v3/company/%s/%s?%s',
            $this->baseUrl,
            rawurlencode($connection->tenantId),
            ltrim($path, '/'),
            http_build_query($query),
        );
    }

    /**
     * Hand-built multipart body, so the package needs no multipart library.
     *
     * @param  array<int, array{name: string, filename: string, type: string, contents: string}>  $parts
     */
    private function multipart(string $boundary, array $parts): string
    {
        $body = '';

        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n";
            $body .= sprintf(
                "Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n",
                $part['name'],
                $part['filename'],
            );
            $body .= "Content-Type: {$part['type']}\r\n\r\n";
            $body .= $part['contents']."\r\n";
        }

        return $body."--{$boundary}--\r\n";
    }

    private function mapper(): QuickBooksPayloadMapper
    {
        return $this->mapper ??= new QuickBooksPayloadMapper;
    }
}
