<?php

declare(strict_types=1);

use Hei\AccountingConnector\Connectors\Xero\XeroConnector;
use Hei\AccountingConnector\Contracts\FindsManualJournals;
use Hei\AccountingConnector\Data\ManualJournal;
use Hei\AccountingConnector\Exceptions\AuthenticationException;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Exceptions\ValidationException;

const JOURNAL_MARKER = '3f2b8c1e-5a7d-4e2f-9b1c-0d4e6a8f2c11';

/**
 * One Xero manual journal row as GET ManualJournals returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function xeroJournalRow(string $id, string $narration, array $overrides = []): array
{
    return $overrides + [
        'ManualJournalID' => $id,
        'Narration' => $narration,
        'Date' => '/Date(1759622400000+0000)/',
        'Status' => 'POSTED',
        'LineAmountTypes' => 'NoTax',
        'UpdatedDateUTC' => '/Date(1759660000000+0000)/',
        'JournalLines' => [],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function journalsPage(array $rows, ?int $page = null, ?int $itemCount = null, int $pageSize = 100): array
{
    $body = ['ManualJournals' => $rows];

    if ($itemCount !== null) {
        $body['pagination'] = [
            'page' => $page ?? 1,
            'pageSize' => $pageSize,
            'pageCount' => (int) max(1, ceil($itemCount / $pageSize)),
            'itemCount' => $itemCount,
        ];
    }

    return $body;
}

/**
 * Rows that cannot match the marker, for filling pages.
 *
 * @return array<int, array<string, mixed>>
 */
function unrelatedJournals(int $from, int $to): array
{
    return array_map(fn (int $i): array => xeroJournalRow('j-'.$i, 'Month end accrual '.$i), range($from, $to));
}

function reclassNarration(string $marker = JOURNAL_MARKER): string
{
    return "AccountingPipe reclass {$marker}: Acme Supply INV-7, bank transaction bt-1";
}

it('is an optional capability the Xero connector has', function () {
    expect(xeroReader(fakeHttp()))->toBeInstanceOf(FindsManualJournals::class);
});

it('asks Xero for journals whose narration contains the marker, in the connection tenant', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage([xeroJournalRow('mj-1', reclassNarration())], 1, 1));

    $journals = xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER);

    expect($fake->requests)->toHaveCount(1)
        ->and((string) $fake->requests[0]->getUri())->toStartWith(XeroConnector::API_BASE.'/ManualJournals?')
        ->and($fake->requests[0]->getHeaderLine('xero-tenant-id'))->toBe('tenant-1')
        ->and(queryOf($fake, 0))->toBe([
            'where' => 'Narration!=null&&Narration.Contains("'.JOURNAL_MARKER.'")',
            'order' => 'ManualJournalID ASC',
            'page' => '1',
            'pageSize' => '100',
        ]);

    expect($journals)->toHaveCount(1);

    $journal = $journals[0];

    expect($journal)->toBeInstanceOf(ManualJournal::class)
        ->and($journal->id)->toBe('mj-1')
        ->and($journal->narration)->toBe(reclassNarration())
        ->and($journal->status)->toBe(ManualJournal::STATUS_POSTED)
        ->and($journal->isPosted())->toBeTrue()
        ->and($journal->date?->format('Y-m-d'))->toBe('2025-10-05')
        ->and($journal->updatedAt?->getTimestamp())->toBe(1759660000)
        ->and($journal->tenantId)->toBe('tenant-1');
});

it('answers an empty list when Xero counts nothing, in one call', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage([], 1, 0));

    expect(xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER))->toBe([])
        ->and($fake->requests)->toHaveCount(1);
});

it('re-checks every row itself and keeps only an exact, whole-token, same-case match', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage([
        xeroJournalRow('upper', reclassNarration(strtoupper(JOURNAL_MARKER))),
        xeroJournalRow('longer', 'AccountingPipe reclass '.JOURNAL_MARKER.'-2: something else'),
        xeroJournalRow('glued', 'AccountingPipe reclass x'.JOURNAL_MARKER),
        xeroJournalRow('dashed', 'AccountingPipe reclass-'.JOURNAL_MARKER),
        xeroJournalRow('underscore', 'AccountingPipe reclass '.JOURNAL_MARKER.'_b'),
        xeroJournalRow('prefix', 'AccountingPipe reclass '.substr(JOURNAL_MARKER, 0, 30)),
        xeroJournalRow('no-narration', '', ['Narration' => null]),
        xeroJournalRow('colon', reclassNarration()),
        xeroJournalRow('at-end', 'AccountingPipe reversal '.JOURNAL_MARKER),
        xeroJournalRow('at-start', JOURNAL_MARKER.' of journal mj-0'),
    ], 1, 10));

    $journals = xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER);

    expect(array_map(fn (ManualJournal $j): string => $j->id, $journals))->toBe(['colon', 'at-end', 'at-start']);
});

it('returns every journal that carries the marker, for the host to refuse an ambiguous answer', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage([
        xeroJournalRow('mj-1', reclassNarration()),
        xeroJournalRow('mj-2', reclassNarration(), ['Status' => 'voided']),
    ], 1, 2));

    $journals = xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER);

    expect($journals)->toHaveCount(2)
        ->and($journals[1]->status)->toBe(ManualJournal::STATUS_VOIDED)
        ->and($journals[1]->isVoidedOrDeleted())->toBeTrue()
        ->and($journals[1]->isPosted())->toBeFalse();
});

it('reads on through a full counted page and stops once the count is reached', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage(unrelatedJournals(1, 100), 1, 150));
    $fake->queue(200, journalsPage([...unrelatedJournals(101, 149), xeroJournalRow('mj-150', reclassNarration())], 2, 150));

    $journals = xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER);

    expect($journals)->toHaveCount(1)
        ->and($journals[0]->id)->toBe('mj-150')
        ->and($fake->requests)->toHaveCount(2)
        ->and(queryOf($fake, 1)['page'])->toBe('2');
});

it('reads on past a short counted page that has not reached the count', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage(unrelatedJournals(1, 40), 1, 41));
    $fake->queue(200, journalsPage([xeroJournalRow('mj-41', reclassNarration())], 2, 41));

    $journals = xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER);

    expect($journals)->toHaveCount(1)
        ->and($fake->requests)->toHaveCount(2);
});

it('does not take a short uncounted page as the end: only an empty page proves it', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage(unrelatedJournals(1, 3)));
    $fake->queue(200, journalsPage([xeroJournalRow('mj-4', reclassNarration())]));
    $fake->queue(200, journalsPage([]));

    $journals = xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER);

    expect($journals)->toHaveCount(1)
        ->and($journals[0]->id)->toBe('mj-4')
        ->and($fake->requests)->toHaveCount(3)
        ->and(queryOf($fake, 2)['page'])->toBe('3');
});

it('spends one more call to prove an uncounted single match is the only one', function () {
    $fake = fakeHttp();
    $fake->queue(200, journalsPage([xeroJournalRow('mj-1', reclassNarration())]));
    $fake->queue(200, journalsPage([]));

    expect(xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER))->toHaveCount(1)
        ->and($fake->requests)->toHaveCount(2);
});

it('refuses to answer when the read cannot be proven complete', function (array $pages, string $why) {
    $fake = fakeHttp();

    foreach ($pages as $page) {
        $fake->queue(200, $page);
    }

    expect(fn () => xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER))
        ->toThrow(ServerException::class, $why);
})->with([
    'an empty page before the count is reached' => [
        [journalsPage(unrelatedJournals(1, 2), 1, 3), journalsPage([], 2, 3)],
        'Xero counted 3 but 2 were read before an empty page',
    ],
    'more rows than counted' => [
        [journalsPage(unrelatedJournals(1, 3), 1, 2)],
        'Xero counted 2 but 3 were read',
    ],
    'a row seen twice across pages' => [
        [journalsPage(unrelatedJournals(1, 100)), journalsPage(unrelatedJournals(100, 101))],
        'journal j-100 came back twice',
    ],
    'the count moving between pages' => [
        [journalsPage(unrelatedJournals(1, 100), 1, 150), journalsPage(unrelatedJournals(101, 120), 2, 151)],
        'the item count moved from 150 to 151',
    ],
    'a body with no ManualJournals list' => [
        [['Status' => 'OK']],
        'page 1 carried no ManualJournals list',
    ],
    'a row with no id' => [
        [journalsPage([xeroJournalRow('', reclassNarration())], 1, 1)],
        'page 1 carried a journal with no id',
    ],
]);

it('gives up after the page cap rather than read without end', function () {
    $fake = fakeHttp();

    for ($page = 0; $page < XeroConnector::MANUAL_JOURNAL_MAX_PAGES; $page++) {
        $fake->queue(200, journalsPage(unrelatedJournals($page * 100 + 1, $page * 100 + 100)));
    }

    expect(fn () => xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER))
        ->toThrow(ServerException::class, 'more than 10 pages of candidates')
        ->and($fake->requests)->toHaveCount(XeroConnector::MANUAL_JOURNAL_MAX_PAGES);
});

it('refuses a marker it cannot ask for safely, before any request', function (string $marker) {
    $fake = fakeHttp();

    expect(fn () => xeroReader($fake)->findManualJournalsByMarker(connection(), $marker))
        ->toThrow(InvalidPayloadException::class)
        ->and($fake->requests)->toHaveCount(0);
})->with([
    'a double quote' => ['abcdefgh"'],
    'a quote breaking out of the filter' => ['x")||Status=="POSTED'],
    'too short' => ['abc1234'],
    'too long' => [str_repeat('a', 101)],
    'a space' => ['abcd efgh'],
    'a leading dash' => ['-abcdefgh'],
    'empty' => [''],
]);

it('propagates a rejected lookup instead of treating it as no journal', function (int $status, string $exception) {
    $fake = fakeHttp();
    $fake->queue($status, ['Message' => 'Request rejected']);

    expect(fn () => xeroReader($fake)->findManualJournalsByMarker(connection(), JOURNAL_MARKER))->toThrow($exception)
        ->and($fake->requests)->toHaveCount(1);
})->with([
    'invalid request' => [400, ValidationException::class],
    'revoked grant' => [401, ConnectionRevokedException::class],
    'missing scope' => [403, AuthenticationException::class],
]);
