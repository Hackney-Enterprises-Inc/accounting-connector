<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Tests\Contract;

use DateTimeImmutable;
use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\ConnectionStore;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Http\HttpClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * Where the contract suite gets its credentials, and nothing else.
 *
 * Deliberately not the application's environment. The app suite scrubs
 * XERO_CLIENT_ID and friends before Laravel boots so that no test can reach a real
 * provider; this suite exists to reach one on purpose, so it reads its own names
 * (XERO_CONTRACT_*) from its own file (.env.contract, gitignored) and from the
 * process environment. Neither side can leak into the other.
 *
 * No dotenv dependency: the file format is KEY=VALUE, one per line, with optional
 * quotes and # comments, which is all a credentials file needs.
 */
final class ContractEnvironment
{
    /** @var array<int, string> */
    public const REQUIRED = [
        'XERO_CONTRACT_CLIENT_ID',
        'XERO_CONTRACT_CLIENT_SECRET',
        'XERO_CONTRACT_TENANT_ID',
        'XERO_CONTRACT_TOKEN_FILE',
    ];

    /**
     * Optional. When absent the suite picks the first bank account and the first
     * expense account it finds in the demo company's chart.
     *
     * @var array<int, string>
     */
    public const OPTIONAL = [
        'XERO_CONTRACT_BANK_ACCOUNT_ID',
        'XERO_CONTRACT_EXPENSE_ACCOUNT_CODE',
    ];

    /**
     * @param  array<string, string>  $values
     */
    private function __construct(
        private readonly array $values,
        private readonly string $baseDir,
    ) {}

    /**
     * Read the file, then let the process environment override it.
     *
     * A missing file is not an error here: missing() reports what is absent and the
     * test case turns that into a skip with the names in the message.
     */
    public static function load(string $envFile): self
    {
        $values = is_file($envFile) ? self::parse((string) file_get_contents($envFile)) : [];

        foreach (array_merge(self::REQUIRED, self::OPTIONAL) as $name) {
            $fromProcess = getenv($name);

            if (is_string($fromProcess) && $fromProcess !== '') {
                $values[$name] = $fromProcess;
            }
        }

        return new self($values, dirname($envFile));
    }

    /**
     * KEY=VALUE lines. Blank lines and lines starting with # are ignored; a value may
     * be wrapped in single or double quotes.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if ($name !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * Every required variable that is absent or empty, plus the token file when it
     * is named but cannot be read. Empty means the suite can run.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $missing = [];

        foreach (self::REQUIRED as $name) {
            if ($this->get($name) === null) {
                $missing[] = $name;
            }
        }

        if ($this->get('XERO_CONTRACT_TOKEN_FILE') !== null && ! is_readable($this->tokenFile())) {
            $missing[] = 'XERO_CONTRACT_TOKEN_FILE (file not readable: '.$this->tokenFile().')';
        }

        return $missing;
    }

    public function get(string $name): ?string
    {
        $value = $this->values[$name] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * The token file, resolved relative to the directory holding .env.contract.
     */
    public function tokenFile(): string
    {
        $path = (string) $this->get('XERO_CONTRACT_TOKEN_FILE');

        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        return $this->baseDir.'/'.$path;
    }

    /**
     * The stored token set: access_token, refresh_token, expires_at (unix seconds or
     * ISO 8601), refresh_token_expires_at (same, optional).
     *
     * @return array<string, mixed>
     */
    public function readTokens(): array
    {
        $decoded = json_decode((string) file_get_contents($this->tokenFile()), true);

        if (! is_array($decoded) || empty($decoded['access_token'])) {
            throw new RuntimeException(sprintf(
                'The token file %s must hold a JSON object with at least access_token and refresh_token.',
                $this->tokenFile(),
            ));
        }

        return $decoded;
    }

    /**
     * A Connection for the demo company, built from the token file.
     *
     * The expiry is read from the file so that a token stored yesterday is refreshed
     * before the first request rather than tried and 401ed.
     */
    public function connection(): Connection
    {
        $tokens = $this->readTokens();

        return new Connection(
            provider: Provider::Xero,
            tenantId: (string) $this->get('XERO_CONTRACT_TENANT_ID'),
            accessToken: (string) $tokens['access_token'],
            refreshToken: isset($tokens['refresh_token']) ? (string) $tokens['refresh_token'] : null,
            expiresAt: self::toDate($tokens['expires_at'] ?? null) ?? new DateTimeImmutable('-1 minute'),
            refreshTokenExpiresAt: self::toDate($tokens['refresh_token_expires_at'] ?? null),
            settings: [],
            reference: 'contract-suite',
        );
    }

    /**
     * The package's own HTTP layer over a real PSR-18 client.
     *
     * One retry, not the default three: a contract case that provokes a 4xx wants to
     * see it, and a case that provokes a 429 should fail fast rather than sleep.
     */
    public function httpClient(ClientInterface $client, int $maxRetries = 1): HttpClient
    {
        return new HttpClient(
            client: $client,
            requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
            streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
            maxRetries: $maxRetries,
        );
    }

    /**
     * A real connector: no service provider, no database, no cache. The store is
     * what writes rotated tokens back to the file.
     */
    public function connector(HttpClient $http, ConnectionStore $store): XeroConnector
    {
        return new XeroConnector(
            http: $http,
            clientId: (string) $this->get('XERO_CONTRACT_CLIENT_ID'),
            clientSecret: (string) $this->get('XERO_CONTRACT_CLIENT_SECRET'),
            redirectUri: 'https://localhost/contract-suite/callback',
            connections: $store,
        );
    }

    private static function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new DateTimeImmutable)->setTimestamp((int) $value);
        }

        if (is_string($value)) {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
