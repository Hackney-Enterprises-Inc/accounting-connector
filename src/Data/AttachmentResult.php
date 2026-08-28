<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

/**
 * What happened to an attachment upload.
 *
 * Returned, never thrown. This is deliberate and is ported behaviour, not a design
 * preference: the transaction has already been created in the customer's ledger by
 * the time an attachment is uploaded. Throwing here would fail the job, the job
 * would retry, and the retry would post a second transaction. A failed attachment
 * is a degraded success, so it reports itself as one and the host decides whether
 * to care.
 */
final readonly class AttachmentResult
{
    private function __construct(
        public bool $uploaded,
        public ?string $filename = null,
        public ?int $bytes = null,
        public ?string $externalId = null,
        /** Why it did not upload. Null on success. */
        public ?string $reason = null,
    ) {}

    public static function success(string $filename, int $bytes, ?string $externalId = null): self
    {
        return new self(uploaded: true, filename: $filename, bytes: $bytes, externalId: $externalId);
    }

    /**
     * Every candidate exceeded the provider's ceiling.
     */
    public static function tooLarge(int $smallestBytes, int $limitBytes): self
    {
        return new self(
            uploaded: false,
            bytes: $smallestBytes,
            reason: sprintf(
                'Smallest candidate is %.2f MB, over the %.2f MB provider limit.',
                $smallestBytes / 1024 / 1024,
                $limitBytes / 1024 / 1024,
            ),
        );
    }

    public static function failed(string $reason, ?string $filename = null): self
    {
        return new self(uploaded: false, filename: $filename, reason: $reason);
    }

    /**
     * The upload was never attempted, by choice rather than by failure.
     *
     * The connectors do not produce this; it exists for hosts and fakes that decide
     * an attachment is not warranted (already attached, feature disabled) and want
     * the decision to travel through the same result type.
     */
    public static function skipped(string $reason): self
    {
        return new self(uploaded: false, reason: $reason);
    }
}
