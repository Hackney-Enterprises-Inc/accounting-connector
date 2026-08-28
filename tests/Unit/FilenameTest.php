<?php

declare(strict_types=1);

use Hei\AccountingConnector\Support\Filename;

it('adds the extension a mime type implies', function () {
    // Xero renders an attachment by extension alone: a PDF named "receipt" with no
    // extension uploads fine and then previews as a broken file.
    expect(Filename::withExtensionFor('receipt', 'application/pdf'))->toBe('receipt.pdf')
        ->and(Filename::withExtensionFor('scan', 'image/png'))->toBe('scan.png');
});

it('corrects an extension that contradicts the mime type', function () {
    expect(Filename::withExtensionFor('receipt.txt', 'application/pdf'))->toBe('receipt.pdf');
});

it('leaves a correct extension alone, whatever its case', function () {
    expect(Filename::withExtensionFor('receipt.pdf', 'application/pdf'))->toBe('receipt.pdf')
        ->and(Filename::withExtensionFor('receipt.PDF', 'application/pdf'))->toBe('receipt.PDF');
});

it('normalises the two spellings of a jpeg', function () {
    expect(Filename::withExtensionFor('photo', 'image/jpeg'))->toBe('photo.jpg')
        ->and(Filename::withExtensionFor('photo', 'image/jpg'))->toBe('photo.jpg');
});

it('leaves a filename alone when the mime type is unknown or missing', function () {
    expect(Filename::withExtensionFor('data.bin', 'application/x-whatever'))->toBe('data.bin')
        ->and(Filename::withExtensionFor('data.bin', null))->toBe('data.bin');
});

it('does not produce a filename that is only an extension', function () {
    expect(Filename::withExtensionFor('.pdf', 'application/pdf'))->toBe('attachment.pdf');
});

it('strips characters that would break a Xero URL path', function () {
    // Xero puts the filename in the URL path, so a slash in a vendor's original
    // filename turns a valid endpoint into a 404.
    expect(Filename::sanitise('invoices/2026/march.pdf'))->toBe('invoices-2026-march.pdf')
        ->and(Filename::sanitise('what?.pdf'))->toBe('what-.pdf')
        ->and(Filename::sanitise('  '))->toBe('attachment');
});
