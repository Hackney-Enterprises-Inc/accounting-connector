<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\Attachment;
use Hei\AccountingConnector\Data\AttachmentSet;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

function attachmentOf(int $bytes, string $name = 'doc.pdf', string $mime = 'application/pdf'): Attachment
{
    return new Attachment($name, str_repeat('x', $bytes), $mime);
}

it('picks the first candidate that fits', function () {
    $set = AttachmentSet::of(attachmentOf(100), attachmentOf(10));

    expect($set->firstUnder(1000)?->size())->toBe(100);
});

it('falls through an oversized candidate to a smaller rendering', function () {
    // The behaviour ported from AccountingPipe: an email rendered to PDF regularly
    // exceeds Xero's 10 MB ceiling, and the same email rendered to PNG does not.
    $set = AttachmentSet::of(
        attachmentOf(500, 'email.pdf'),
        attachmentOf(50, 'email.png', 'image/png'),
    );

    $chosen = $set->firstUnder(100);

    expect($chosen?->filename)->toBe('email.png')
        ->and($chosen?->size())->toBe(50);
});

it('reports nothing when every rendering is too large', function () {
    $set = AttachmentSet::of(attachmentOf(500), attachmentOf(400));

    expect($set->firstUnder(100))->toBeNull()
        ->and($set->smallest()->size())->toBe(400);
});

it('accepts a candidate exactly on the limit', function () {
    expect(AttachmentSet::of(attachmentOf(100))->firstUnder(100))->not->toBeNull();
});

it('wraps a bare attachment into a single-candidate set', function () {
    $set = AttachmentSet::wrap(attachmentOf(10));

    expect($set)->toBeInstanceOf(AttachmentSet::class)
        ->and($set->count())->toBe(1);
});

it('refuses an empty file', function () {
    new Attachment('empty.pdf', '', 'application/pdf');
})->throws(InvalidPayloadException::class);

it('refuses an empty candidate list', function () {
    AttachmentSet::of();
})->throws(InvalidPayloadException::class);

it('normalises the filename it will upload under', function () {
    expect(attachmentOf(10, 'receipt', 'application/pdf')->normalisedFilename())->toBe('receipt.pdf');
});
