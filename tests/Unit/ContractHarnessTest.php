<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Tests\Contract\ContractEnvironment;
use Hei\AccountingConnector\Tests\Contract\FileConnectionStore;

/**
 * The contract harness, proved offline.
 *
 * The contract suite itself (tests/Contract, `composer test:contract`) talks to a
 * Xero Demo Company and is never part of this run. What CAN be proved here is the
 * part that keeps it safe: it refuses to start without its own credentials, and
 * a refreshed token is written back to the file before it is used.
 */
function contractScratchDir(): string
{
    $dir = sys_get_temp_dir().'/accounting-connector-contract-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

/**
 * The XERO_CONTRACT_* variables of the process, blanked for one test so a developer
 * who has exported them does not make the "missing" case pass or fail by accident.
 *
 * @return array<string, string|false>
 */
function withoutContractVariables(): array
{
    $previous = [];

    foreach (array_merge(ContractEnvironment::REQUIRED, ContractEnvironment::OPTIONAL) as $name) {
        $previous[$name] = getenv($name);
        putenv($name);
    }

    return $previous;
}

/**
 * @param  array<string, string|false>  $previous
 */
function restoreContractVariables(array $previous): void
{
    foreach ($previous as $name => $value) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv("{$name}={$value}");
        }
    }
}

it('reports every required variable missing when there is no .env.contract, and reads no token file', function (): void {
    $previous = withoutContractVariables();

    try {
        $environment = ContractEnvironment::load(contractScratchDir().'/.env.contract');

        expect($environment->missing())->toBe(ContractEnvironment::REQUIRED)
            ->and($environment->get('XERO_CONTRACT_TOKEN_FILE'))->toBeNull();
    } finally {
        restoreContractVariables($previous);
    }
});

it('names the token file when the variables are present but the file is not', function (): void {
    $previous = withoutContractVariables();

    try {
        $dir = contractScratchDir();

        file_put_contents($dir.'/.env.contract', implode("\n", [
            '# a comment',
            'XERO_CONTRACT_CLIENT_ID=client',
            'XERO_CONTRACT_CLIENT_SECRET="secret with spaces"',
            "XERO_CONTRACT_TENANT_ID='tenant'",
            'XERO_CONTRACT_TOKEN_FILE=missing-token.json',
        ]));

        $environment = ContractEnvironment::load($dir.'/.env.contract');

        expect($environment->get('XERO_CONTRACT_CLIENT_SECRET'))->toBe('secret with spaces')
            ->and($environment->get('XERO_CONTRACT_TENANT_ID'))->toBe('tenant')
            ->and($environment->tokenFile())->toBe($dir.'/missing-token.json')
            ->and($environment->missing())->toBe(['XERO_CONTRACT_TOKEN_FILE (file not readable: '.$dir.'/missing-token.json)']);
    } finally {
        restoreContractVariables($previous);
    }
});

it('lets the process environment override the file', function (): void {
    $previous = withoutContractVariables();

    try {
        $dir = contractScratchDir();
        file_put_contents($dir.'/.env.contract', "XERO_CONTRACT_CLIENT_ID=from-file\n");
        putenv('XERO_CONTRACT_CLIENT_ID=from-process');

        expect(ContractEnvironment::load($dir.'/.env.contract')->get('XERO_CONTRACT_CLIENT_ID'))->toBe('from-process');
    } finally {
        restoreContractVariables($previous);
    }
});

it('rewrites the token file when the connector refreshes the connection', function (): void {
    $dir = contractScratchDir();
    $tokenFile = $dir.'/token.json';

    file_put_contents($tokenFile, json_encode([
        'access_token' => 'stale-access',
        'refresh_token' => 'old-refresh',
        'expires_at' => time() - 60,
    ]));

    $fake = fakeHttp();
    $fake->queue(200, [
        'access_token' => 'fresh-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 1800,
    ]);

    $store = new FileConnectionStore($tokenFile);
    $xero = new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        connections: $store,
    );

    $stale = new Connection(
        provider: Provider::Xero,
        tenantId: 'tenant-1',
        accessToken: 'stale-access',
        refreshToken: 'old-refresh',
        expiresAt: new DateTimeImmutable('-1 minute'),
    );

    $refreshed = $xero->refresh($stale);

    $stored = json_decode((string) file_get_contents($tokenFile), true);

    expect($refreshed->accessToken)->toBe('fresh-access')
        ->and($stored['access_token'])->toBe('fresh-access')
        ->and($stored['refresh_token'])->toBe('rotated-refresh')
        ->and($stored['tenant_id'])->toBe('tenant-1')
        ->and($stored['expires_at'])->toBeGreaterThan(time())
        ->and(substr(sprintf('%o', fileperms($tokenFile)), -4))->toBe('0600');
});

it('builds a connection from the token file that refreshes before its first request when the token is stale', function (): void {
    $previous = withoutContractVariables();

    try {
        $dir = contractScratchDir();

        file_put_contents($dir.'/token.json', json_encode([
            'access_token' => 'stale-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => time() - 60,
        ]));

        file_put_contents($dir.'/.env.contract', implode("\n", [
            'XERO_CONTRACT_CLIENT_ID=client',
            'XERO_CONTRACT_CLIENT_SECRET=secret',
            'XERO_CONTRACT_TENANT_ID=tenant-1',
            'XERO_CONTRACT_TOKEN_FILE=token.json',
        ]));

        $environment = ContractEnvironment::load($dir.'/.env.contract');
        $connection = $environment->connection();

        expect($environment->missing())->toBe([])
            ->and($connection->tenantId)->toBe('tenant-1')
            ->and($connection->refreshToken)->toBe('old-refresh')
            ->and($connection->isExpired())->toBeTrue();
    } finally {
        restoreContractVariables($previous);
    }
});
