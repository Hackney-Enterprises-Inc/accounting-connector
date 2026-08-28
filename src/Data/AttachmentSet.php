<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * Candidate renderings of the same document, best first.
 *
 * This exists because of a real problem AccountingPipe solved and this package
 * must not lose. Xero caps attachments at 10 MB. A forwarded email rendered to PDF
 * regularly blows past that, while the same email rendered to PNG does not. The
 * fix is to offer several renderings and let the connector take the first one that
 * fits the provider's limit.
 *
 * Rendering is the host's job. The package cannot turn a PDF into a PNG and should
 * not try; it just picks. So a host that has a PDF and a PNG passes both:
 *
 *     AttachmentSet::of($pdf, $png)
 *
 * and gets back an AttachmentResult naming which one actually went up.
 */
final readonly class AttachmentSet
{
    /**
     * @param  array<int, Attachment>  $candidates
     */
    private function __construct(
        public array $candidates,
    ) {
        if ($this->candidates === []) {
            throw new InvalidPayloadException('An attachment set needs at least one candidate.');
        }
    }

    public static function of(Attachment ...$candidates): self
    {
        return new self(array_values($candidates));
    }

    /**
     * Normalise whatever the caller passed into a set.
     */
    public static function wrap(Attachment|self $attachment): self
    {
        return $attachment instanceof self ? $attachment : self::of($attachment);
    }

    /**
     * The first candidate within the limit, or null when every one is too large.
     */
    public function firstUnder(int $maxBytes): ?Attachment
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->size() <= $maxBytes) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The smallest candidate, for reporting how badly an oversized set missed.
     */
    public function smallest(): Attachment
    {
        $sorted = $this->candidates;
        usort($sorted, fn (Attachment $a, Attachment $b): int => $a->size() <=> $b->size());

        return $sorted[0];
    }

    public function count(): int
    {
        return count($this->candidates);
    }
}
