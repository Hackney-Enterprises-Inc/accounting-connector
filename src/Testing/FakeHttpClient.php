<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Testing;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * A PSR-18 client that answers from a queue instead of a network.
 *
 * For testing the connectors themselves. Applications should reach for
 * FakeConnector instead; this is a level lower and exists so the wire format can be
 * asserted directly.
 *
 *     $http = new FakeHttpClient($responseFactory, $streamFactory);
 *     $http->queue(200, ['Invoices' => [['InvoiceID' => 'abc']]]);
 *
 *     // ... call the connector ...
 *
 *     expect($http->requests[0]->getHeaderLine('xero-tenant-id'))->toBe('tenant-1');
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var array<int, RequestInterface> */
    public array $requests = [];

    /** @var array<int, string> */
    public array $bodies = [];

    /** @var array<int, ResponseInterface> */
    private array $queued = [];

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /**
     * Queue a JSON response.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function queue(int $status, array $body = [], array $headers = []): self
    {
        return $this->queueRaw($status, json_encode($body, JSON_THROW_ON_ERROR), $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * Queue a response with a body exactly as given, for non-JSON cases.
     *
     * @param  array<string, string>  $headers
     */
    public function queueRaw(int $status, string $body, array $headers = []): self
    {
        $response = $this->responses->createResponse($status)->withBody($this->streams->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $this->queued[] = $response;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();

        $response = array_shift($this->queued);

        if ($response === null) {
            throw new RuntimeException(sprintf(
                'FakeHttpClient has no queued response for %s %s. Queue one with queue().',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        return $response;
    }

    /**
     * The decoded body of the nth request, for asserting on what was sent.
     *
     * @return array<string, mixed>
     */
    public function requestBody(int $index = 0): array
    {
        $decoded = json_decode($this->bodies[$index] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    /**
     * Whether every queued response was consumed.
     */
    public function isDrained(): bool
    {
        return $this->queued === [];
    }
}
