<?php

declare(strict_types=1);

use Hei\AccountingConnector\Data\JournalData;
use Hei\AccountingConnector\Data\JournalLine;
use Hei\AccountingConnector\Data\ManualJournal;
use Hei\AccountingConnector\Data\Money;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Tests\Contract\ContractCase;

uses(ContractCase::class);

/**
 * Case 8. What findManualJournalsByMarker() rests on (AP-MATCH-26).
 *
 * Xero's documentation does not say whether ManualJournals takes a Contains filter
 * on Narration, whether Contains is case-sensitive, whether the paged answer carries
 * `pagination.itemCount`, or whether a voided journal is still listed. The lookup is
 * written to be right under every answer (it re-checks rows locally and proves the
 * end of the read); this case records which answers Xero actually gives, and fails
 * if the lookup cannot find a journal it just posted.
 *
 * The fixture is a balanced two-line POSTED journal between two expense accounts,
 * voided at the end.
 */
it('finds a posted journal by the marker in its narration, and records how Xero filters (AP-MATCH-26)', function (): void {
    $marker = str_replace('.', '-', 'apc-'.self::runId().'-case8-'.bin2hex(random_bytes(4)));
    $first = $this->expenseAccountCode();
    $second = $this->anotherExpenseAccountCode($first);

    $journal = new JournalData(
        narration: $this->reference('case8').' marker '.$marker.': contract fixture',
        date: new DateTimeImmutable('today'),
        lines: [
            new JournalLine($first, Money::cents(1234), 'Contract fixture debit'),
            new JournalLine($second, Money::cents(-1234), 'Contract fixture credit'),
        ],
        currency: $this->baseCurrency(),
    );

    $id = $this->xero->createEntity(EntityType::Journal, $journal, $this->freshConnection(), 'contract-'.$marker);

    expect($id)->toBeString()->not->toBe('');

    try {
        $found = $this->xero->findManualJournalsByMarker($this->freshConnection(), $marker);

        expect(array_map(fn (ManualJournal $j): string => $j->id, $found))->toBe([$id])
            ->and($found[0]->isPosted())->toBeTrue();

        $plain = $this->raw('GET', 'ManualJournals', [
            'where' => 'Narration!=null&&Narration.Contains("'.$marker.'")',
            'page' => 1,
            'pageSize' => 100,
        ]);
        $upper = $this->raw('GET', 'ManualJournals', [
            'where' => 'Narration!=null&&Narration.Contains("'.strtoupper($marker).'")',
            'page' => 1,
        ]);
        $upperRows = $upper->get('ManualJournals', []);

        $this->recordAnswer('case 8 manual journal marker lookup', sprintf(
            'lookup found %s; pagination block %s (itemCount %s, pageCount %s); Contains with the marker upper-cased returned %s, so Contains is %s',
            $id,
            is_array($plain->get('pagination')) ? 'present' : 'ABSENT',
            var_export($plain->get('pagination.itemCount'), true),
            var_export($plain->get('pagination.pageCount'), true),
            $upper->successful() ? (is_array($upperRows) ? count($upperRows).' row(s)' : 'no list') : 'HTTP '.$upper->status,
            $upper->successful() && is_array($upperRows) && $upperRows !== [] ? 'case-INSENSITIVE' : 'case-sensitive (or the probe failed)',
        ));
    } finally {
        $void = $this->raw('POST', 'ManualJournals/'.$id, [], [
            'ManualJournals' => [['ManualJournalID' => $id, 'Status' => 'VOIDED']],
        ]);

        $after = $void->successful() ? $this->xero->findManualJournalsByMarker($this->freshConnection(), $marker) : [];

        $this->recordAnswer('case 8 cleanup', $void->successful()
            ? sprintf('voided %s; a voided journal %s', $id, $after === [] ? 'is NOT listed by the lookup' : 'is still listed, status '.($after[0]->status ?? 'null'))
            : "could not void {$id}: HTTP {$void->status}");
    }
});
