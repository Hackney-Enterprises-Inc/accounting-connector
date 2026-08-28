<?php

declare(strict_types=1);

use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\RateLimitException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Http\HttpClient;
use Hei\AccountingConnector\Http\NullSleeper;
use Hei\AccountingConnector\Testing\FakeHttpClient;
use Http\Discovery\Psr17FactoryDiscovery;

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
