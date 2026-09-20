<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Tests\Contract;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A real transport that remembers what went over it.
 *
 * Several contract cases assert that something did NOT happen on the wire ("a stale
 * expectation is refused with no POST"), and the only honest evidence for that is a
 * log of every request the connector actually sent. Wrapping the PSR-18 client keeps
 * the connector code untouched.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, status: int}> */
    public array $requests = [];

    public function __construct(private readonly ClientInterface $inner) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);

        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'status' => $response->getStatusCode(),
        ];

        return $response;
    }

    /**
     * Requests of one method, in order.
     *
     * @return array<int, array{method: string, url: string, status: int}>
     */
    public function of(string $method): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $entry): bool => $entry['method'] === strtoupper($method),
        ));
    }

    public function count(string $method): int
    {
        return count($this->of($method));
    }
}
