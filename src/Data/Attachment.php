<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Support\Filename;

/**
 * A file to hang off a posted transaction, held in memory as raw bytes.
 *
 * Bytes, not a path or a storage disk, because the package has no filesystem. Both
 * consuming apps keep documents on S3-compatible storage behind their own disk
 * abstraction and read them out before calling.
 */
final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $contents,
        public string $mimeType,
    ) {
        if ($this->contents === '') {
            throw new InvalidPayloadException("Attachment '{$this->filename}' is empty.");
        }
    }

    /**
     * Build from a local path, for CLI tools and tests.
     *
     * Neither consuming app uses this in production; both read from object storage.
     */
    public static function fromPath(string $path, ?string $filename = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidPayloadException("Attachment file is missing or unreadable: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidPayloadException("Could not read attachment file: {$path}");
        }

        $mimeType = (function_exists('mime_content_type') ? mime_content_type($path) : false)
            ?: 'application/octet-stream';

        return new self(
            filename: $filename ?? basename($path),
            contents: $contents,
            mimeType: $mimeType,
        );
    }

    /**
     * Size in bytes.
     */
    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * The filename with the extension its mime type implies.
     *
     * Xero renders an attachment by extension alone: a PDF named "receipt" with no
     * extension uploads fine and then shows the customer a broken preview. Ported
     * verbatim from AccountingPipe, where this was found the hard way.
     */
    public function normalisedFilename(): string
    {
        return Filename::withExtensionFor($this->filename, $this->mimeType);
    }

    /**
     * A copy under a different name, keeping the same bytes.
     */
    public function renamed(string $filename): self
    {
        return new self($filename, $this->contents, $this->mimeType);
    }
}
