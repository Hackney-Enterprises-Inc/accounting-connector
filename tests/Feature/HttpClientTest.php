<?php

declare(strict_types=1);

use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\RateLimitException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Http\HttpClient;
use Hei\AccountingConnector\Http\HttpResponse;
use Hei\AccountingConnector\Http\NullSleeper;
use Hei\AccountingConnector\Http\RequestGate;
use Hei\AccountingConnector\Testing\FakeHttpClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

function clientWithSleeper(FakeHttpClient $fake, NullSleeper $sleeper, int $retries = 3): HttpClient
{
    return new HttpClient(
        client: $fake,
        requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
        streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
        sleeper: $sleeper,
        maxRetries: $retries,
    );
}

it('waits exactly as long as Retry-After says on a 429', function () {
    // Guessing is how you get an app banned. Xero tells you the number; honour it.
    $fake = fakeHttp();
    $fake->queue(429, [], ['Retry-After' => '7']);
    $fake->queue(200, ['ok' => true]);

    $sleeper = new NullSleeper;

    $response = clientWithSleeper($fake, $sleeper)->send('GET', 'https://api.xero.com/x');

    expect($response->status)->toBe(200)
        ->and($sleeper->slept)->toBe([7.0]);
});

it('caps an absurd Retry-After rather than hanging a queue worker', function () {
    $fake = fakeHttp();
    $fake->queue(429, [], ['Retry-After' => '86400']);
    $fake->queue(200, ['ok' => true]);

    $sleeper = new NullSleeper;

    clientWithSleeper($fake, $sleeper)->send('GET', 'https://api.xero.com/x');

    expect($sleeper->slept)->toBe([60.0]);
});

it('gives up on rate limits once the retry budget is spent', function () {
    $fake = fakeHttp();
    foreach (range(1, 5) as $ignored) {
        $fake->queue(429, [], ['Retry-After' => '1', 'X-Rate-Limit-Problem' => 'minute']);
    }

    expect(fn () => clientWithSleeper($fake, new NullSleeper, retries: 2)
        ->send('GET', 'https://api.xero.com/x', provider: Provider::Xero))
        ->toThrow(RateLimitException::class);
});

it('carries Retry-After onto the rate limit exception so a job can requeue sensibly', function () {
    $fake = fakeHttp();
    $fake->queue(429, [], ['Retry-After' => '30']);

    try {
        clientWithSleeper($fake, new NullSleeper, retries: 0)
            ->send('GET', 'https://api.xero.com/x', provider: Provider::Xero);
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(30)
            ->and($e->provider)->toBe(Provider::Xero);

        return;
    }

    $this->fail('Expected a RateLimitException.');
});

it('retries a 5xx and succeeds when the provider recovers', function () {
    $fake = fakeHttp();
    $fake->queue(503, []);
    $fake->queue(500, []);
    $fake->queue(200, ['ok' => true]);

    $sleeper = new NullSleeper;

    expect(clientWithSleeper($fake, $sleeper)->send('GET', 'https://api.xero.com/x')->status)->toBe(200)
        ->and($sleeper->slept)->toHaveCount(2);
});

it('backs off with jitter so parallel workers do not retry in lockstep', function () {
    // Several queue workers hitting a recovering API at the same instant is how it
    // gets knocked back over.
    $fake = fakeHttp();
    $fake->queue(500, []);
    $fake->queue(500, []);
    $fake->queue(200, ['ok' => true]);

    $sleeper = new NullSleeper;
    clientWithSleeper($fake, $sleeper)->send('GET', 'https://api.xero.com/x');

    // Full jitter picks somewhere in [0, ceiling], and the ceiling doubles each time.
    expect($sleeper->slept[0])->toBeLessThanOrEqual(0.5)
        ->and($sleeper->slept[1])->toBeLessThanOrEqual(1.0);
});

it('throws a server exception when the outage outlasts the retries', function () {
    $fake = fakeHttp();
    foreach (range(1, 5) as $ignored) {
        $fake->queue(500, []);
    }

    expect(fn () => clientWithSleeper($fake, new NullSleeper, retries: 2)
        ->send('GET', 'https://api.xero.com/x', provider: Provider::Xero))
        ->toThrow(ServerException::class);
});

it('never retries a 4xx, because the same payload will be rejected the same way', function () {
    $fake = fakeHttp();
    $fake->queue(400, ['Message' => 'nope']);

    $sleeper = new NullSleeper;

    expect(clientWithSleeper($fake, $sleeper)->send('POST', 'https://api.xero.com/x')->status)->toBe(400)
        ->and($sleeper->slept)->toBeEmpty()
        ->and($fake->requests)->toHaveCount(1);
});

it('surfaces the remaining-call counters a host can throttle itself against', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['ok' => true], [
        'X-MinLimit-Remaining' => '11',
        'X-DayLimit-Remaining' => '4200',
        'X-AppMinLimit-Remaining' => '9000',
    ]);

    $remaining = clientWithSleeper($fake, new NullSleeper)
        ->send('GET', 'https://api.xero.com/x')
        ->rateLimitRemaining();

    expect($remaining)->toBe(['minute' => 11, 'day' => 4200, 'app_minute' => 9000]);
});

it('does not explode on an HTML error page from a provider edge', function () {
    // Both vendors serve HTML maintenance pages at their edge more often than you
    // would hope, and a JSON decode failure must not mask the real status.
    $fake = fakeHttp();
    $fake->queueRaw(404, '<html><body>Not Found</body></html>', ['Content-Type' => 'text/html']);

    $response = clientWithSleeper($fake, new NullSleeper, retries: 0)->send('GET', 'https://api.xero.com/x');

    expect($response->status)->toBe(404)
        ->and($response->json())->toBe([]);
});

/**
 * A gate that counts, and can be told to refuse.
 */
function recordingGate(?RateLimitException $refuseWith = null): RequestGate
{
    return new class($refuseWith) implements RequestGate
    {
        /** @var array<int, array{provider: string|null, tenant: string|null}> */
        public array $acquired = [];

        /** @var array<int, array{provider: string|null, tenant: string|null, failure: string}> */
        public array $released = [];

        public function __construct(private readonly ?RateLimitException $refuseWith) {}

        public function acquire(?Provider $provider, ?string $tenantId): void
        {
            $this->acquired[] = ['provider' => $provider?->value, 'tenant' => $tenantId];

            if ($this->refuseWith !== null) {
                throw $this->refuseWith;
            }
        }

        public function release(?Provider $provider, ?string $tenantId, Throwable $failure): void
        {
            $this->released[] = ['provider' => $provider?->value, 'tenant' => $tenantId, 'failure' => $failure->getMessage()];
        }
    };
}

/** A PSR-18 client that never answers: every send is a transport failure. */
function timingOutHttpClient(string $message = 'Connection timed out'): ClientInterface
{
    return new class($message) implements ClientInterface
    {
        public function __construct(private readonly string $message) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class($this->message, $request) extends RuntimeException implements NetworkExceptionInterface
            {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        }
    };
}

function clientWithGate(FakeHttpClient $fake, RequestGate $gate, int $retries = 3): HttpClient
{
    return new HttpClient(
        client: $fake,
        requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
        streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
        sleeper: new NullSleeper,
        maxRetries: $retries,
        gate: $gate,
    );
}

it('asks the gate before every attempt, retries included', function () {
    // A retry is a request too, and the allowance it spends is the same allowance.
    $fake = fakeHttp();
    $fake->queue(429, [], ['Retry-After' => '1']);
    $fake->queue(503, []);
    $fake->queue(200, ['ok' => true]);

    $gate = recordingGate();

    clientWithGate($fake, $gate)->send('GET', 'https://api.xero.com/x', provider: Provider::Xero, tenantId: 'tenant-1');

    expect($gate->acquired)->toHaveCount(3)
        ->and($gate->acquired[0])->toBe(['provider' => 'xero', 'tenant' => 'tenant-1'])
        ->and($fake->requests)->toHaveCount(3);
});

it('lets the gate refuse a request before it is made', function () {
    $fake = fakeHttp();
    $fake->queue(200, ['ok' => true]);

    $gate = recordingGate(new RateLimitException('Daily reserve reached.', Provider::Xero, 3600));

    expect(fn () => clientWithGate($fake, $gate)->send('GET', 'https://api.xero.com/x', provider: Provider::Xero))
        ->toThrow(RateLimitException::class, 'Daily reserve reached.');

    // Nothing left the process: the queued response is still there.
    expect($fake->requests)->toBeEmpty()
        ->and($fake->isDrained())->toBeFalse();
});

it('hands every response to the listeners, the ones it retries included', function () {
    // A 429 about to be retried is exactly the moment a budget wants to hear about.
    $fake = fakeHttp();
    $fake->queue(429, [], ['Retry-After' => '1', 'X-Rate-Limit-Problem' => 'minute', 'X-MinLimit-Remaining' => '0']);
    $fake->queue(200, ['ok' => true], ['X-MinLimit-Remaining' => '41', 'X-DayLimit-Remaining' => '4210']);

    $seen = [];

    $client = clientWithSleeper($fake, new NullSleeper)->afterResponse(
        function (HttpResponse $response, ?Provider $provider, ?string $tenantId) use (&$seen): void {
            $seen[] = [
                'status' => $response->status,
                'problem' => $response->header('x-rate-limit-problem'),
                'remaining' => $response->rateLimitRemaining(),
                'provider' => $provider?->value,
                'tenant' => $tenantId,
            ];
        },
    );

    $client->send('GET', 'https://api.xero.com/x', provider: Provider::Xero, tenantId: 'tenant-9');

    expect($seen)->toHaveCount(2)
        ->and($seen[0]['status'])->toBe(429)
        ->and($seen[0]['problem'])->toBe('minute')
        ->and($seen[0]['remaining']['minute'])->toBe(0)
        ->and($seen[1]['status'])->toBe(200)
        ->and($seen[1]['remaining'])->toBe(['minute' => 41, 'day' => 4210, 'app_minute' => null])
        ->and($seen[1]['provider'])->toBe('xero')
        ->and($seen[1]['tenant'])->toBe('tenant-9');
});

it('never lets a listener fail the request', function () {
    // A metrics hook must not be the reason a posting failed.
    $fake = fakeHttp();
    $fake->queue(200, ['ok' => true]);

    $client = clientWithSleeper($fake, new NullSleeper)->afterResponse(function (): void {
        throw new RuntimeException('metrics backend down');
    });

    $response = $client->send('GET', 'https://api.xero.com/x');

    expect($response->status)->toBe(200);
});

it('hands every attempt that produced no response back to the gate', function () {
    // A timeout admits an attempt and then never reports a response, so the gate
    // would otherwise keep whatever it reserved (an in-flight slot) until it
    // expired on its own: four timeouts, four slots gone, the fifth request
    // refused as "concurrent" while the provider is fine.
    $gate = recordingGate();
    $client = new HttpClient(
        client: timingOutHttpClient('Connection timed out after 30000 milliseconds'),
        requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
        streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
        sleeper: new NullSleeper,
        maxRetries: 3,
        gate: $gate,
    );

    expect(fn () => $client->send('GET', 'https://api.xero.com/x', provider: Provider::Xero, tenantId: 'tenant-1'))
        ->toThrow(ServerException::class);

    expect($gate->acquired)->toHaveCount(4)
        ->and($gate->released)->toHaveCount(4)
        ->and($gate->released[0])->toBe(['provider' => 'xero', 'tenant' => 'tenant-1', 'failure' => 'Connection timed out after 30000 milliseconds']);
});

it('never lets a gate fault on release replace the transport failure', function () {
    $gate = new class implements RequestGate
    {
        public function acquire(?Provider $provider, ?string $tenantId): void {}

        public function release(?Provider $provider, ?string $tenantId, Throwable $failure): void
        {
            throw new RuntimeException('gate exploded');
        }
    };
    $client = new HttpClient(
        client: timingOutHttpClient(),
        requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
        streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
        sleeper: new NullSleeper,
        maxRetries: 1,
        gate: $gate,
    );

    expect(fn () => $client->send('GET', 'https://api.xero.com/x', provider: Provider::Xero, tenantId: 'tenant-1'))
        ->toThrow(ServerException::class, 'timed out');
});

it('reports a transport timeout as a server exception once the retries are spent', function () {
    // What a Guzzle timeout looks like to PSR-18: a client exception, which this
    // client retries and then reports. The point is that it is reported at all,
    // rather than hanging a scheduled command forever without a timeout.
    $client = new HttpClient(
        client: new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class('Connection timed out after 30000 milliseconds') extends RuntimeException implements NetworkExceptionInterface
                {
                    public function getRequest(): RequestInterface
                    {
                        return Psr17FactoryDiscovery::findRequestFactory()->createRequest('GET', 'https://api.xero.com/x');
                    }
                };
            }
        },
        requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
        streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
        sleeper: new NullSleeper,
        maxRetries: 1,
    );

    expect(fn () => $client->send('GET', 'https://api.xero.com/x', provider: Provider::Xero))
        ->toThrow(ServerException::class, 'timed out');
});
