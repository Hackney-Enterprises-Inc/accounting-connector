<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroDate;

it('sends a plain ISO date', function () {
    expect(XeroDate::toXero(new DateTimeImmutable('2026-08-21 14:30:00')))->toBe('2026-08-21');
});

it('parses the Microsoft JSON date format Xero answers with', function () {
    // The official SDK hides this inside its deserialiser, so it is easy to forget
    // it exists until every parsed transaction lands in 1970.
    expect(XeroDate::parse('/Date(1518685950940+0000)/')?->format('Y-m-d'))->toBe('2018-02-15')
        ->and(XeroDate::parse('/Date(1518685950940)/')?->format('Y-m-d'))->toBe('2018-02-15');
});

it('parses a plain ISO date too, since Xero is not consistent', function () {
    expect(XeroDate::parse('2026-08-21')?->format('Y-m-d'))->toBe('2026-08-21')
        ->and(XeroDate::parse('2026-08-21T00:00:00')?->format('Y-m-d'))->toBe('2026-08-21');
});

it('handles a pre-epoch date without wrapping', function () {
    expect(XeroDate::parse('/Date(-2208988800000+0000)/')?->format('Y'))->toBe('1900');
});

it('returns null rather than throwing on something unparseable', function () {
    // A date we cannot read is not a reason to fail a sync that already posted.
    expect(XeroDate::parse('not a date at all'))->toBeNull()
        ->and(XeroDate::parse(''))->toBeNull()
        ->and(XeroDate::parse(null))->toBeNull();
});

it('reads a calendar date as midnight UTC, whatever the default timezone', function (string $value) {
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/Los_Angeles');

    try {
        expect(XeroDate::parseDate($value)?->format('Y-m-d H:i:s e'))->toBe('2018-12-31 00:00:00 UTC');
    } finally {
        date_default_timezone_set($previous);
    }
})->with([
    'Microsoft JSON date' => ['/Date(1546214400000+0000)/'],
    'ISO date' => ['2018-12-31'],
    'ISO date and time' => ['2018-12-31T00:00:00'],
]);

it('returns null for a calendar date it cannot read', function (?string $value) {
    expect(XeroDate::parseDate($value))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'words' => ['not a date'],
    'impossible day' => ['2018-02-30'],
    'relative words strtotime would accept' => ['next monday'],
    'malformed Microsoft date' => ['/Date(abc)/'],
]);
