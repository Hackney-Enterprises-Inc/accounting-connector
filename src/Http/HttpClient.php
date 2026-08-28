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
 */
final class HttpClient
{
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
    ) {}

    /**
     * Build one with whatever PSR-18 and PSR-17 implementations are installed.
     *
     * Every Laravel application already ships Guzzle, so discovery finds one.
     */
    public static function discover(
        ?LoggerInterface $logger = null,
        ?Sleeper $sleeper = null,
        int $maxRetries = 3,
    ): self {
        return new self(
            client: Psr18ClientDiscovery::find(),
            requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
            streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
            logger: $logger ?? new NullLogger,
            sleeper: $sleeper ?? new RealSleeper,
            maxRetries: $maxRetries,
        );
    }

    /**
     * Send a request, retrying the retryable.
     *
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?Provider $provider = null,
    ): HttpResponse {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->dispatch($method, $url, $headers, $body);
            } catch (ClientExceptionInterface $e) {
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
