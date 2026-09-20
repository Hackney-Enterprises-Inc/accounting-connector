<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Tests\Contract;

use DateTimeImmutable;
use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Data\Account;
use Hei\AccountingConnector\Data\BankTransactionChange;
use Hei\AccountingConnector\Data\BankTransactionData;
use Hei\AccountingConnector\Data\BankTransactionQuery;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\ExpenseData;
use Hei\AccountingConnector\Data\LineCoding;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Enums\AccountClass;
use Hei\AccountingConnector\Enums\BankTransactionType;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Http\HttpClient;
use Hei\AccountingConnector\Http\HttpResponse;
use Http\Discovery\Psr18ClientDiscovery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The base of every contract case: a real connector against a Xero Demo Company.
 *
 * THIS SUITE TALKS TO XERO. It is not part of the default test run and cannot be
 * reached by `composer test` or by the application's suite: it needs its own
 * configuration file (phpunit.contract.xml), its own credentials (.env.contract) and
 * its own token file, and it skips itself, naming what is missing, when any of them
 * is absent.
 *
 * Why it exists: the connector is written against Xero's documentation, and the
 * documentation is silent on the things the matching plan depends on most (whether a
 * reconciled spend money can be recoded, what a delete leaves behind, how a page is
 * ordered, how large a page may be). A fake cannot answer those; only Xero can. Each
 * case records what Xero actually did in build/contract-answers.md so the plan can
 * cite a run rather than a guess.
 *
 * Fixtures: every transaction this suite creates carries a reference beginning
 * AP-CONTRACT- followed by the run id. They are deleted in tearDown once the package
 * has deleteBankTransaction (T10); until then their ids are recorded as left behind
 * and the final cleanup case sweeps them on a later run.
 */
abstract class ContractCase extends TestCase
{
    public const REFERENCE_PREFIX = 'AP-CONTRACT-';

    public const ANSWERS_FILE = __DIR__.'/../../build/contract-answers.md';

    private static ?string $runId = null;

    protected ContractEnvironment $environment;

    protected RecordingHttpClient $requests;

    protected HttpClient $http;

    protected FileConnectionStore $store;

    protected XeroConnector $xero;

    protected Connection $connection;

    /** @var array<int, string> Bank transaction ids this test created and must remove. */
    protected array $createdTransactionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = ContractEnvironment::load(__DIR__.'/../../.env.contract');

        $missing = $this->environment->missing();

        if ($missing !== []) {
            $this->markTestSkipped(
                'Contract suite skipped: missing '.implode(', ', $missing)
                .'. Copy .env.contract.example to .env.contract at the package root and fill it in.'
            );
        }

        $this->requests = new RecordingHttpClient(Psr18ClientDiscovery::find());
        $this->http = $this->environment->httpClient($this->requests);
        $this->store = new FileConnectionStore($this->environment->tokenFile());
        $this->xero = $this->environment->connector($this->http, $this->store);
        $this->connection = $this->environment->connection();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTransactionIds as $id) {
            $this->removeFixture($id);
        }

        $this->createdTransactionIds = [];

        parent::tearDown();
    }

    /**
     * One id per process, so every fixture from one run shares a reference prefix and
     * a sweep can tell this run's leftovers from an older run's.
     */
    public static function runId(): string
    {
        return self::$runId ??= (new DateTimeImmutable)->format('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6);
    }

    protected function reference(string $tag): string
    {
        return self::REFERENCE_PREFIX.self::runId().'-'.$tag;
    }

    /**
     * Skip, naming the task, when a case depends on a connector method that a later
     * task adds. Keeps the suite runnable at every point in the build order.
     */
    protected function requireConnectorMethod(string $method, string $task): void
    {
        if (! method_exists($this->xero, $method)) {
            $this->markTestSkipped("Waits on {$task}: XeroConnector::{$method}() does not exist yet.");
        }
    }

    /**
     * A one-line SPEND in the demo company, remembered for removal.
     */
    protected function createSpend(int $cents, string $tag, ?string $accountCode = null): string
    {
        $expense = new ExpenseData(
            vendor: 'AP Contract Fixture',
            date: new DateTimeImmutable('today'),
            lines: [new LineItem(
                description: 'Contract suite fixture '.$tag,
                unitAmount: Money::cents($cents),
                quantity: 1.0,
                accountCode: $accountCode ?? $this->expenseAccountCode(),
            )],
            currency: $this->baseCurrency(),
            bankAccount: $this->bankAccountId(),
            reference: $this->reference($tag),
            localId: 'contract-'.$tag.'-'.bin2hex(random_bytes(3)),
        );

        $id = $this->xero->createEntity(EntityType::Expense, $expense, $this->connection);

        if ($id === null) {
            throw new RuntimeException('Xero accepted the fixture SPEND but returned no id.');
        }

        $this->createdTransactionIds[] = $id;

        return $id;
    }

    protected function find(string $id): ?BankTransactionData
    {
        return $this->xero->findBankTransaction($this->connection, $id);
    }

    /**
     * Recode through whichever method the package has at this point in the build.
     *
     * recodeBankTransaction (T8) is preferred because it is the contract the plan
     * depends on; updateBankTransactionCoding is what exists today and answers the
     * same question for case 1.
     *
     * @param  array<int, LineCoding>  $codings
     */
    protected function recodeLines(string $id, array $codings): BankTransactionData
    {
        if (method_exists($this->xero, 'recodeBankTransaction')) {
            /** @phpstan-ignore-next-line T8 adds this method and its DTOs. */
            $result = $this->xero->recodeBankTransaction(
                $this->connection,
                $id,
                new BankTransactionChange($codings),
            );

            return $result->after;
        }

        return $this->xero->updateBankTransactionCoding($this->connection, $id, $codings);
    }

    /**
     * A reconciled, single-line, coded SPEND from the demo company's own history.
     *
     * Cannot be created: the API sets IsReconciled only for conversion apps. Walks up
     * to $maxPages pages of AUTHORISED spend and returns the first that fits.
     */
    protected function findReconciledSingleLineSpend(int $maxPages = 5): ?BankTransactionData
    {
        $query = new BankTransactionQuery(type: BankTransactionType::Spend, status: 'AUTHORISED');

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->xero->listBankTransactions($this->connection, $query);

            foreach ($result->transactions as $transaction) {
                $line = $transaction->lines[0] ?? null;

                if ($transaction->isReconciled
                    && count($transaction->lines) === 1
                    && $line !== null
                    && $line->lineItemId !== null
                    && $line->isCoded()
                    && ! str_starts_with((string) $transaction->reference, self::REFERENCE_PREFIX)) {
                    return $transaction;
                }
            }

            if (! $result->hasMore()) {
                break;
            }

            $query = $query->nextPage();
        }

        return null;
    }

    /**
     * An active expense account other than the one given, from the chart.
     */
    protected function anotherExpenseAccountCode(string $not): string
    {
        foreach (Account::only($this->xero->chartOfAccounts($this->connection), AccountClass::Expense) as $account) {
            if ($account->code !== null && $account->code !== '' && $account->code !== $not) {
                return $account->code;
            }
        }

        throw new RuntimeException("The demo company's chart has no expense account other than {$not}.");
    }

    protected function expenseAccountCode(): string
    {
        $configured = $this->environment->get('XERO_CONTRACT_EXPENSE_ACCOUNT_CODE');

        if ($configured !== null) {
            return $configured;
        }

        foreach (Account::only($this->xero->chartOfAccounts($this->connection), AccountClass::Expense) as $account) {
            if ($account->code !== null && $account->code !== '') {
                return $account->code;
            }
        }

        throw new RuntimeException("The demo company's chart has no expense account with a code.");
    }

    protected function bankAccountId(): string
    {
        $configured = $this->environment->get('XERO_CONTRACT_BANK_ACCOUNT_ID');

        if ($configured !== null) {
            return $configured;
        }

        $accounts = $this->xero->bankAccounts($this->connection);

        if ($accounts === []) {
            throw new RuntimeException('The demo company has no bank account to post a fixture SPEND to.');
        }

        return $accounts[0]->id;
    }

    protected function baseCurrency(): string
    {
        return $this->xero->tenantInfo($this->connection)?->currencyCode ?? 'USD';
    }

    /**
     * A request the connector has no method for, sent with the connection's own
     * headers. Used by the probes (VOIDED, pageSize, unitdp) whose whole point is
     * to ask Xero something the package does not yet know how to ask.
     *
     * @param  array<string, string|int>  $query
     * @param  array<string, mixed>|null  $body
     */
    protected function raw(string $method, string $resource, array $query = [], ?array $body = null): HttpResponse
    {
        $connection = $this->freshConnection();

        $url = XeroConnector::API_BASE.'/'.ltrim($resource, '/');

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer '.$connection->accessToken,
            'xero-tenant-id' => $connection->tenantId,
            'Accept' => 'application/json',
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->http->send(
            $method,
            $url,
            $headers,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            $this->xero->provider(),
        );
    }

    /**
     * The connection with a live access token, refreshing through the connector (and
     * therefore through the file store) when the stored one has expired.
     */
    protected function freshConnection(): Connection
    {
        if ($this->connection->isExpired()) {
            $this->connection = $this->xero->refresh($this->connection);
        }

        return $this->connection;
    }

    /**
     * Write what Xero actually did to build/contract-answers.md, appending, with the
     * run id and the time, so the plan's section 8 can cite a run.
     */
    protected function recordAnswer(string $case, string $answer): void
    {
        $directory = dirname(self::ANSWERS_FILE);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $line = sprintf(
            "- %s run %s, %s: %s\n",
            (new DateTimeImmutable)->format(DATE_ATOM),
            self::runId(),
            $case,
            $answer,
        );

        file_put_contents(self::ANSWERS_FILE, $line, FILE_APPEND);
    }

    /**
     * Remove a fixture, or record that it could not be removed yet.
     */
    protected function removeFixture(string $id): void
    {
        if (! method_exists($this->xero, 'deleteBankTransaction')) {
            $this->recordAnswer('cleanup', "left behind {$id}: deleteBankTransaction arrives with T10; the cleanup case sweeps it later");

            return;
        }

        try {
            /** @phpstan-ignore-next-line T10 adds this method. */
            $this->xero->deleteBankTransaction($this->connection, $id);
        } catch (Throwable $e) {
            $this->recordAnswer('cleanup', "could not delete {$id}: ".$e->getMessage());
        }
    }
}
