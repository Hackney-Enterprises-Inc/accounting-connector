<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

use Hei\AccountingConnector\Contracts\FindsManualJournals;
use Hei\AccountingConnector\Enums\Provider;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * The marker rules {@see FindsManualJournals}
 * promises, in one place so the real connector and the fake cannot drift apart.
 *
 * The character set is narrow on purpose. Xero's `where` has no escape syntax, so a
 * marker must never carry a quote or anything else that could end or bend the
 * expression; and a marker made only of word characters and `-` has an unambiguous
 * edge, which is what lets "whole token" be checked locally.
 */
final class NarrationMarker
{
    public const PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{7,99}\z/';

    /**
     * @throws InvalidPayloadException when the marker breaks the rules
     */
    public static function assertUsable(string $marker, ?Provider $provider = null): void
    {
        if (preg_match(self::PATTERN, $marker) !== 1) {
            throw new InvalidPayloadException(
                'A journal narration marker must be 8 to 100 letters, digits, "_" or "-", starting with a letter or digit.',
                $provider,
            );
        }
    }

    /**
     * Whether the narration carries the marker exactly, as a whole token.
     *
     * Case-sensitive, and the characters either side must not be a letter, digit,
     * `_` or `-`, so `abc` does not match inside `xabc` or `abc-2`. A UUID followed by
     * a colon, a space or the end of the narration matches.
     */
    public static function matches(string $narration, string $marker): bool
    {
        return preg_match('/(?<![A-Za-z0-9_-])'.preg_quote($marker, '/').'(?![A-Za-z0-9_-])/', $narration) === 1;
    }
}
