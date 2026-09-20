<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Data\BillData;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\LineItem;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Http\HttpClient;
use Hei\AccountingConnector\Http\NullSleeper;
use Hei\AccountingConnector\Support\ArrayLookupStore;
use Hei\AccountingConnector\Testing\FakeHttpClient;
use Hei\AccountingConnector\Tests\TestCase;
use Http\Discovery\Psr17FactoryDiscovery;

/**
 * A connection whose access token is comfortably in date, so nothing refreshes
 * unless a test wants it to.
 *
 * @param  array<string, mixed>  $settings
 */
function connection(Provider $provider = Provider::Xero, array $settings = [], ?string $expires = '+30 minutes'): Connection
{
    return new Connection(
        provider: $provider,
        tenantId: 'tenant-1',
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        expiresAt: $expires === null ? null : (new DateTimeImmutable)->modify($expires),
        settings: $settings,
        reference: 'org-99',
    );
}

/**
 * A canned provider response from tests/Fixtures.
 *
 * Doc-derived, not captured: shapes come from the Xero OpenAPI spec and the Intuit
 * API reference, cross-checked against what the connectors parse. Provenance and
 * the field-by-field notes live in .claude/docs/04-provider-response-shapes.md.
 *
 * @return array<string, mixed>
 */
function providerResponse(string $name): array
{
    $decoded = json_decode(
        (string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    assert(is_array($decoded));

    return $decoded;
}

/**
 * A fake PSR-18 client, wired to whatever PSR-17 factories are installed.
 */
function fakeHttp(): FakeHttpClient
{
    return new FakeHttpClient(
        Psr17FactoryDiscovery::findResponseFactory(),
        Psr17FactoryDiscovery::findStreamFactory(),
    );
}

/**
 * An HttpClient over a fake transport that never actually waits.
 */
function httpClientOver(FakeHttpClient $fake, int $maxRetries = 3): HttpClient
{
    return new HttpClient(
        client: $fake,
        requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
        streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
        sleeper: new NullSleeper,
        maxRetries: $maxRetries,
    );
}

/**
 * A three-line bill from a named vendor, the shape most tests need.
 */
function billFor(string $vendor = 'Acme Supply'): BillData
{
    return new BillData(
        vendor: $vendor,
        date: new DateTimeImmutable('2026-08-21'),
        lines: [new LineItem(
            description: 'Widgets',
            unitAmount: Money::cents(2500),
            quantity: 3,
            accountCode: '400',
        )],
        localId: 'doc-42',
    );
}

/**
 * A Xero connector wired to a fake transport and an inspectable lookup store.
 */
function xeroWithStore(FakeHttpClient $fake, ?ArrayLookupStore $store = null): XeroConnector
{
    return new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
        lookups: $store ?? new ArrayLookupStore,
    );
}

/**
 * A Xero connector over a fake transport with no retries, for the bank transaction reads.
 */
function xeroReader(FakeHttpClient $fake): XeroConnector
{
    return new XeroConnector(
        http: httpClientOver($fake, maxRetries: 0),
        clientId: 'client-id',
        clientSecret: 'client-secret',
        redirectUri: 'https://app.test/callback',
    );
}

/**
 * The query string of a recorded request, decoded.
 *
 * @return array<string, string>
 */
function queryOf(FakeHttpClient $fake, int $index): array
{
    parse_str((string) $fake->requests[$index]->getUri()->getQuery(), $parsed);

    /** @var array<string, string> $parsed */
    return $parsed;
}

function qboConnection(array $settings = [], ?string $expires = '+30 minutes'): Connection
{
    return new Connection(
        provider: Provider::QuickBooksOnline,
        tenantId: 'realm-1',
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        expiresAt: $expires === null ? null : (new DateTimeImmutable)->modify($expires),
        settings: $settings,
        reference: 'org-99',
    );
}

pest()->extend(TestCase::class)->in('Laravel');
