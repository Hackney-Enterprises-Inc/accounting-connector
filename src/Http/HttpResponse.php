<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Http;

/**
 * A provider response, decoded once and kept.
 *
 * PSR-7 bodies are streams and a stream can only be read to the end once. Reading
 * it here and holding the string means an error path can inspect the body after the
 * happy path already looked at it, which is exactly what error mapping needs.
 */
final class HttpResponse
{
    /** @var array<string, mixed>|null */
    private ?array $decoded = null;

    private bool $attemptedDecode = false;

    /**
     * @param  array<string, array<int, string>>  $headers  Header names lowercased.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * The body decoded as JSON, or an empty array if it is not JSON.
     *
     * Providers return HTML error pages at their edge more often than anyone
     * expects, so this must not explode on a maintenance page.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if (! $this->attemptedDecode) {
            $this->attemptedDecode = true;
            $decoded = json_decode($this->body, true);
            $this->decoded = is_array($decoded) ? $decoded : [];
        }

        return $this->decoded ?? [];
    }

    /**
     * A value from the decoded body, addressed with dots.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->json();

        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            return $default;
        }

        return $value;
    }

    /**
     * Seconds the provider asked us to wait, if it said.
     */
    public function retryAfter(): ?int
    {
        $value = $this->header('retry-after');

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Xero's remaining-call counters, when present.
     *
     * Useful for a host that wants to slow its own queue down before it starts
     * getting 429s, rather than after.
     *
     * @return array{minute: int|null, day: int|null, app_minute: int|null}
     */
    public function rateLimitRemaining(): array
    {
        $read = function (string $header): ?int {
            $value = $this->header($header);

            return $value !== null && ctype_digit($value) ? (int) $value : null;
        };

        return [
            'minute' => $read('x-minlimit-remaining'),
            'day' => $read('x-daylimit-remaining'),
            'app_minute' => $read('x-appminlimit-remaining'),
        ];
    }
}
