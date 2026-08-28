<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Support;

/**
 * Filename hygiene for attachments.
 *
 * Ported from AccountingPipe, where both the Xero and QuickBooks services carried
 * an identical private copy of this.
 */
final class Filename
{
    /**
     * Extensions we can infer with confidence. Anything else is left alone.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/tiff' => 'tiff',
    ];

    /**
     * Give a filename the extension its mime type implies.
     *
     * Both providers render attachments by extension: a PDF uploaded as "receipt"
     * with no extension is accepted and then previews as a broken file in the
     * customer's ledger. An unrecognised mime type is left untouched rather than
     * guessed at.
     */
    public static function withExtensionFor(string $filename, ?string $mimeType): string
    {
        if ($mimeType === null || ! isset(self::EXTENSIONS[$mimeType])) {
            return $filename;
        }

        $expected = self::EXTENSIONS[$mimeType];
        $parts = pathinfo($filename);
        $current = strtolower($parts['extension'] ?? '');

        // ".pdf" parses as an empty name with a correct extension, so the early
        // return below would pass it through and upload a dotfile the customer's
        // ledger renders as a nameless attachment.
        $base = $parts['filename'] === '' ? 'attachment' : $parts['filename'];

        if ($current === $expected && $parts['filename'] !== '') {
            return $filename;
        }

        return $base.'.'.$expected;
    }

    /**
     * Strip characters that break a path segment.
     *
     * Xero puts the filename in the URL path, so a slash or a question mark in a
     * vendor's original filename produces a 404 on an endpoint that exists.
     */
    public static function sanitise(string $filename): string
    {
        $clean = preg_replace('/[\/\\\\?%*:|"<>\x00-\x1F]/', '-', $filename) ?? $filename;
        $clean = trim($clean);

        return $clean === '' ? 'attachment' : $clean;
    }
}
