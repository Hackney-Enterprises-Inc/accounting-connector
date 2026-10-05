<?php

declare(strict_types=1);

use Hei\AccountingConnector\Contracts\FindsManualJournals;
use Hei\AccountingConnector\Data\Connection;
use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\JournalLine;
use Hei\AccountingConnector\Data\ManualJournal;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\TransactionStatus;
use Hei\AccountingConnector\Exceptions\ConnectionRevokedException;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;
use Hei\AccountingConnector\Exceptions\ServerException;
use Hei\AccountingConnector\Support\NarrationMarker;
use Hei\AccountingConnector\Testing\FakeConnector;

const FAKE_MARKER = '9d1e2f3a-4b5c-4d6e-8f70-81a2b3c4d5e6';

function fakeJournal(string $narration, TransactionStatus $status = TransactionStatus::Authorised): JournalData
{
    return new JournalData(
        narration: $narration,
        date: new DateTimeImmutable('2026-10-01'),
        lines: [
            new JournalLine('400', Money::cents(1250)),
            new JournalLine('429', Money::cents(-1250)),
        ],
        status: $status,
    );
}

function otherTenant(): Connection
{
    return new Connection(
        provider: connection()->provider,
        tenantId: 'tenant-2',
        accessToken: 'access-token',
    );
}

it('is a capability the fake has', function () {
    expect(new FakeConnector)->toBeInstanceOf(FindsManualJournals::class);
});

it('finds a seeded journal by its marker and records the lookup', function () {
    $fake = (new FakeConnector)->withManualJournals(
        new ManualJournal('mj-1', 'AccountingPipe reclass '.FAKE_MARKER.': Acme', ManualJournal::STATUS_POSTED, tenantId: 'tenant-1'),
        new ManualJournal('mj-2', 'Month end accrual', ManualJournal::STATUS_POSTED, tenantId: 'tenant-1'),
    );

    $found = $fake->findManualJournalsByMarker(connection(), FAKE_MARKER);

    expect(array_map(fn (ManualJournal $j): string => $j->id, $found))->toBe(['mj-1'])
        ->and($found[0]->tenantId)->toBe('tenant-1')
        ->and($fake->manualJournalLookups)->toBe([['marker' => FAKE_MARKER, 'tenant_id' => 'tenant-1']]);
});

it('keeps tenants apart: a journal seeded for one tenant is not in another, an untenanted one is in all', function () {
    $fake = (new FakeConnector)->withManualJournals(
        new ManualJournal('mine', 'reclass '.FAKE_MARKER, tenantId: 'tenant-1'),
        new ManualJournal('anywhere', 'reversal '.FAKE_MARKER),
    );

    $theirs = $fake->findManualJournalsByMarker(otherTenant(), FAKE_MARKER);

    expect(array_map(fn (ManualJournal $j): string => $j->id, $theirs))->toBe(['anywhere'])
        ->and($theirs[0]->tenantId)->toBe('tenant-2')
        ->and($fake->findManualJournalsByMarker(connection(), FAKE_MARKER))->toHaveCount(2);
});

it('replaces a journal seeded again under the same id and tenant', function () {
    $fake = (new FakeConnector)->withManualJournals(
        new ManualJournal('mj-1', 'reclass '.FAKE_MARKER, ManualJournal::STATUS_POSTED, tenantId: 'tenant-1'),
    )->withManualJournals(
        new ManualJournal('mj-1', 'reclass '.FAKE_MARKER, ManualJournal::STATUS_VOIDED, tenantId: 'tenant-1'),
    );

    $found = $fake->findManualJournalsByMarker(connection(), FAKE_MARKER);

    expect($found)->toHaveCount(1)
        ->and($found[0]->status)->toBe(ManualJournal::STATUS_VOIDED);
});

it('uses the same exact, whole-token rule as the real connector', function () {
    $fake = (new FakeConnector)->withManualJournals(
        new ManualJournal('upper', 'reclass '.strtoupper(FAKE_MARKER)),
        new ManualJournal('longer', 'reclass '.FAKE_MARKER.'-2'),
        new ManualJournal('exact', 'reclass '.FAKE_MARKER.': Acme'),
    );

    expect(array_map(fn (ManualJournal $j): string => $j->id, $fake->findManualJournalsByMarker(connection(), FAKE_MARKER)))
        ->toBe(['exact']);
});

it('makes a journal created through it findable, in the tenant it was posted to', function () {
    $fake = new FakeConnector;

    $id = $fake->createEntity(EntityType::Journal, fakeJournal('AccountingPipe reclass '.FAKE_MARKER.': Acme'), connection(), FAKE_MARKER);

    $found = $fake->findManualJournalsByMarker(connection(), FAKE_MARKER);

    expect($found)->toHaveCount(1)
        ->and($found[0]->id)->toBe($id)
        ->and($found[0]->status)->toBe(ManualJournal::STATUS_POSTED)
        ->and($found[0]->date?->format('Y-m-d'))->toBe('2026-10-01')
        ->and($fake->findManualJournalsByMarker(otherTenant(), FAKE_MARKER))->toBe([]);
});

it('records a draft journal as a draft', function () {
    $fake = new FakeConnector;
    $fake->createEntity(EntityType::Journal, fakeJournal('draft '.FAKE_MARKER, TransactionStatus::Draft), connection());

    expect($fake->findManualJournalsByMarker(connection(), FAKE_MARKER)[0]->status)->toBe(ManualJournal::STATUS_DRAFT);
});

it('lands a journal and then throws when told the create fails after landing', function () {
    $fake = (new FakeConnector)->failNextCreateAfterLanding(new ServerException('timed out after the write'));

    expect(fn () => $fake->createEntity(EntityType::Journal, fakeJournal('reclass '.FAKE_MARKER), connection(), FAKE_MARKER))
        ->toThrow(ServerException::class, 'timed out after the write');

    $found = $fake->findManualJournalsByMarker(connection(), FAKE_MARKER);

    expect($found)->toHaveCount(1)
        ->and($found[0]->id)->toBe('fake-journal-unreported-1')
        ->and($fake->createdOf(EntityType::Journal))->toHaveCount(1);

    // The ids a caller is handed carry on from where they were.
    expect($fake->createEntity(EntityType::Journal, fakeJournal('next one'), connection()))->toBe('fake-journal-1');
});

it('lands nothing when the create fails outright', function () {
    $fake = (new FakeConnector)
        ->failNextCreateAfterLanding(new ServerException('landed'))
        ->failNextCreate(new ServerException('refused'));

    expect(fn () => $fake->createEntity(EntityType::Journal, fakeJournal('reclass '.FAKE_MARKER), connection()))
        ->toThrow(ServerException::class, 'refused');

    expect($fake->findManualJournalsByMarker(connection(), FAKE_MARKER))->toBe([])
        ->and($fake->created)->toBe([]);
});

it('lands a journal whose create answered with no id', function () {
    $fake = (new FakeConnector)->nextCreateReturnsNoId();

    expect($fake->createEntity(EntityType::Journal, fakeJournal('reclass '.FAKE_MARKER), connection()))->toBeNull()
        ->and($fake->findManualJournalsByMarker(connection(), FAKE_MARKER))->toHaveCount(1);
});

it('refuses a marker the real connector would refuse', function () {
    expect(fn () => (new FakeConnector)->findManualJournalsByMarker(connection(), 'x")||1'))
        ->toThrow(InvalidPayloadException::class);
});

it('throws a seeded lookup failure once, then answers again', function () {
    $fake = (new FakeConnector)
        ->withManualJournals(new ManualJournal('mj-1', 'reclass '.FAKE_MARKER))
        ->failNextManualJournalLookup(new ServerException('could not be proven complete'));

    expect(fn () => $fake->findManualJournalsByMarker(connection(), FAKE_MARKER))->toThrow(ServerException::class)
        ->and($fake->findManualJournalsByMarker(connection(), FAKE_MARKER))->toHaveCount(1);
});

it('honours failLookups', function () {
    $fake = (new FakeConnector)->failLookups(new ConnectionRevokedException('gone', connection()));

    expect(fn () => $fake->findManualJournalsByMarker(connection(), FAKE_MARKER))->toThrow(ConnectionRevokedException::class);
});

it('forgets journals and lookups on flush', function () {
    $fake = (new FakeConnector)
        ->withManualJournals(new ManualJournal('mj-1', 'reclass '.FAKE_MARKER))
        ->failNextCreateAfterLanding(new ServerException('landed'));
    $fake->findManualJournalsByMarker(connection(), FAKE_MARKER);

    $fake->flush();

    expect($fake->manualJournals)->toBe([])
        ->and($fake->manualJournalLookups)->toBe([])
        ->and($fake->createEntity(EntityType::Journal, fakeJournal('after flush'), connection()))->toBe('fake-journal-1');
});

it('describes a journal as an array', function () {
    $journal = new ManualJournal(
        'mj-1',
        'reclass '.FAKE_MARKER,
        ManualJournal::STATUS_DELETED,
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2026-10-02T03:04:05+00:00'),
        'tenant-1',
    );

    expect($journal->toArray())->toBe([
        'id' => 'mj-1',
        'narration' => 'reclass '.FAKE_MARKER,
        'status' => 'DELETED',
        'date' => '2026-10-01',
        'updated_at' => '2026-10-02T03:04:05+00:00',
        'tenant_id' => 'tenant-1',
    ])
        ->and($journal->isVoidedOrDeleted())->toBeTrue()
        ->and($journal->isPosted())->toBeFalse();
});

it('accepts a UUID marker and matches it only on whole-token edges', function (string $narration, bool $matches) {
    NarrationMarker::assertUsable(FAKE_MARKER);

    expect(NarrationMarker::matches($narration, FAKE_MARKER))->toBe($matches);
})->with([
    'followed by a colon' => ['reclass '.FAKE_MARKER.': Acme', true],
    'at the end' => ['reversal '.FAKE_MARKER, true],
    'in parentheses' => ['('.FAKE_MARKER.')', true],
    'followed by a dash' => ['reclass '.FAKE_MARKER.'-x', false],
    'after a letter' => ['reclassx'.FAKE_MARKER, false],
    'after a dash' => ['reclass-'.FAKE_MARKER, false],
    'after an underscore' => ['reclass_'.FAKE_MARKER, false],
    'other case' => ['reclass '.strtoupper(FAKE_MARKER), false],
    'absent' => ['reclass', false],
]);
