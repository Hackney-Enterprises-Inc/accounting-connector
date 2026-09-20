<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Http;

use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\RateLimitException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The one place this package makes an HTTP call.
 *
 * Narrow on purpose. Both connectors speak plain JSON REST, so there is no vendored
 * provider SDK anywhere in this package. That is a deliberate choice with real
 * consequences worth knowing about:
 *
 *  - Xero's officially supported SDKs are a convenience, not a requirement, and app
 *    certification cares about behaviour rather than which client library you used.
 *  - Not depending on xeroapi/xero-php-oauth2 keeps its transitive firebase/php-jwt
 *    constraint out of every application that installs this package.
 *  - Retries and rate-limit handling live here, applied identically to both
 *    providers. Neither vendor SDK does this for you.
 *
 * Retry policy: 429 waits for exactly as long as the provider's Retry-After header
 * says, because guessing is how you get banned. 5xx and transport failures back off
 * exponentially with jitter. 4xx other than 429 is never retried, because the same
 * malformed payload will be rejected the same way forever.
 *
 * What this throws versus what it returns follows one rule: anything it retried and
 * still could not get through (429, 5xx, transport failure) is reported as an
 * exception, because the retry budget is spent and the caller has no better move.
 * Anything it never retries is returned as a response for the caller to interpret,
 * because a 400 from Xero and a 400 from Intuit mean different things and only the
 * connector knows which.
 *
 * Two hooks let a host spend its allowance on purpose rather than discover it is
 * gone: a {@see RequestGate} is asked before every attempt, retries included, and
 * every response the client receives is handed to the listeners registered with
 * {@see self::afterResponse()} together with the provider's remaining-call counters.
 * The client itself never budgets; it only makes budgeting possible.
 */
final class HttpClient
{
    /** @var array<int, callable(HttpResponse, Provider|null, string|null): void> */
    private array $afterResponse = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly Sleeper $sleeper = new RealSleeper,
        /** Attempts after the first. Zero disables retrying entirely. */
        private readonly int $maxRetries = 3,
        /** Seconds to wait before the first retry; doubles each attempt. */
        private readonly float $baseDelay = 0.5,
        /** Refuse to honour an absurd Retry-After rather than hanging a worker. */
        private readonly int $maxRetryAfter = 60,
        /** Asked before every attempt. Open by default. */
        private readonly RequestGate $gate = new NullRequestGate,
    ) {}

    /**
     * Build one with whatever PSR-18 and PSR-17 implementations are installed.
     *
     * Every Laravel application already ships Guzzle, so discovery finds one. Pass
     * a client to control what discovery cannot, such as timeouts: a discovered
     * Guzzle client waits forever, and a hung provider call inside a scheduled
     * command has nothing else to stop it.
     */
    public static function discover(
        ?LoggerInterface $logger = null,
        ?Sleeper $sleeper = null,
        int $maxRetries = 3,
        ?RequestGate $gate = null,
        ?ClientInterface $client = null,
    ): self {
        return new self(
            client: $client ?? Psr18ClientDiscovery::find(),
            requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
            streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
            logger: $logger ?? new NullLogger,
            sleeper: $sleeper ?? new RealSleeper,
            maxRetries: $maxRetries,
            gate: $gate ?? new NullRequestGate,
        );
    }

    /**
     * Hear about every response this client receives, retried or not.
     *
     * The listener gets the response (so it can read {@see HttpResponse::rateLimitRemaining()}
     * and the `X-Rate-Limit-Problem` header), the provider and the tenant the request
     * was made for. A 429 that is about to be retried is reported too, because that
     * is exactly when a budget wants to know. Listeners must not throw; one that does
     * is logged and ignored, since a metrics hook must never be the reason a posting
     * failed.
     *
     * @param  callable(HttpResponse, Provider|null, string|null): void  $listener
     */
    public function afterResponse(callable $listener): self
    {
        $this->afterResponse[] = $listener;

        return $this;
    }

    /**
     * Hand an attempt back to the gate after a transport failure.
     *
     * The gate must not throw here, but the request is already failing and a gate
     * fault must not turn a retryable timeout into an unrelated exception.
     */
    private function releaseQuietly(?Provider $provider, ?string $tenantId, ClientExceptionInterface $failure): void
    {
        try {
            $this->gate->release($provider, $tenantId, $failure);
        } catch (Throwable $e) {
            $this->logger->warning('A request gate threw while releasing a failed attempt; ignoring it.', [
                'provider' => $provider?->value,
                'tenant' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a request, retrying the retryable.
     *
     * @param  array<string, string>  $headers
     * @param  string|null  $tenantId  The provider tenant the request is for, when there is one.
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?Provider $provider = null,
        ?string $tenantId = null,
    ): HttpResponse {
        $attempt = 0;

        while (true) {
            $attempt++;

            // Before every attempt, not only the first: a retry is a request too,
            // and the allowance it spends is the same allowance.
            $this->gate->acquire($provider, $tenantId);

            try {
                $response = $this->dispatch($method, $url, $headers, $body);
            } catch (ClientExceptionInterface $e) {
                // No response will reach the listeners for this attempt, so the
                // gate hears about it here or never: an in-flight slot it reserved
                // would otherwise sit taken until it expired.
                $this->releaseQuietly($provider, $tenantId, $e);

                if ($attempt > $this->maxRetries) {
                    throw new ServerException(
                        sprintf('Could not reach %s after %d attempts: %s', $this->host($url), $attempt, $e->getMessage()),
                        $provider,
                        $e->getMessage(),
                        0,
                        $e,
                    );
                }

                $this->backOff($attempt, $url, 'transport failure: '.$e->getMessage());

                continue;
            }

            $this->observe($response, $provider, $tenantId);

            if ($response->status === 429) {
                if ($attempt > $this->maxRetries) {
                    throw new RateLimitException(
                        sprintf('%s rate limit hit and the retry budget is spent.', $provider?->label() ?? $this->host($url)),
                        $provider,
                        $response->retryAfter(),
                        $this->limitProblem($response),
                    );
                }

                $wait = min($response->retryAfter() ?? (int) ceil($this->baseDelay * (2 ** ($attempt - 1))), $this->maxRetryAfter);

                $this->logger->warning('Accounting provider rate limited the request; waiting as instructed.', [
                    'provider' => $provider?->value,
                    'url' => $this->redact($url),
                    'attempt' => $attempt,
                    'retry_after' => $wait,
                    'limit' => $this->limitProblem($response),
                    'remaining' => $response->rateLimitRemaining(),
                ]);

                $this->sleeper->sleep((float) $wait);

                continue;
            }

            if ($response->status >= 500) {
                if ($attempt <= $this->maxRetries) {
                    $this->backOff($attempt, $url, "HTTP {$response->status}");

                    continue;
                }

                // Exhausted. Throwing rather than returning keeps the rule coherent
                // with the 429 path above: anything this client retried and still
                // could not get through is its own to report, while a status it
                // never retries is the caller's to interpret.
                throw new ServerException(
                    sprintf(
                        '%s returned HTTP %d for %s and was still failing after %d attempts.',
                        $provider?->label() ?? $this->host($url),
                        $response->status,
                        $this->redact($url),
                        $attempt,
                    ),
                    $provider,
                    $response->body === '' ? null : substr($response->body, 0, 500),
                );
            }

            return $response;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dispatch(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        $response = $this->client->sendRequest($request);

        $normalised = [];
        foreach ($response->getHeaders() as $name => $values) {
            $normalised[strtolower($name)] = array_values($values);
        }

        return new HttpResponse(
            status: $response->getStatusCode(),
            body: (string) $response->getBody(),
            headers: $normalised,
        );
    }

    /**
     * Hand a received response to the listeners, without letting one of them fail the call.
     */
    private function observe(HttpResponse $response, ?Provider $provider, ?string $tenantId): void
    {
        foreach ($this->afterResponse as $listener) {
            try {
                $listener($response, $provider, $tenantId);
            } catch (Throwable $e) {
                $this->logger->warning('An accounting connector response listener threw and was ignored.', [
                    'provider' => $provider?->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function backOff(int $attempt, string $url, string $reason): void
    {
        // Full jitter. Several queue workers retrying a provider outage in lockstep
        // is how a recovering API gets knocked back over.
        $ceiling = $this->baseDelay * (2 ** ($attempt - 1));
        $wait = $ceiling * (random_int(0, 1000) / 1000);

        $this->logger->info('Retrying accounting provider request.', [
            'url' => $this->redact($url),
            'attempt' => $attempt,
            'reason' => $reason,
            'wait_seconds' => round($wait, 3),
        ]);

        $this->sleeper->sleep($wait);
    }

    /**
     * Which of Xero's several ceilings we hit, when it says.
     */
    private function limitProblem(HttpResponse $response): ?string
    {
        return $response->header('x-rate-limit-problem');
    }

    private function host(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: $url;
    }

    /**
     * Strip the query string before a URL reaches a log.
     *
     * QuickBooks puts the whole query statement in the URL, and a query statement
     * can carry a customer's vendor names.
     */
    private function redact(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '[unparseable url]';
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '');
    }
}
