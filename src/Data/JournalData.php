<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;
use Hei\AccountingConnector\Contracts\EntityPayload;
use Hei\AccountingConnector\Enums\EntityType;
use Hei\AccountingConnector\Enums\TransactionStatus;
use Hei\AccountingConnector\Exceptions\InvalidPayloadException;

/**
 * A balanced manual journal entry.
 *
 * Posts as a Xero ManualJournal or a QuickBooks JournalEntry. Used for things no
 * invoice or bill describes cleanly, such as attributing a payment processor's
 * payout across gross, fees and net.
 *
 * The entry must balance. Both providers reject an unbalanced journal, but they
 * reject it after the round trip and with a message that does not name the amount
 * it is out by, so this checks first.
 */
final readonly class JournalData implements EntityPayload
{
    /**
     * @param  array<int, JournalLine>  $lines
     */
    public function __construct(
        public string $narration,
        public DateTimeImmutable $date,
        public array $lines,
        public string $currency = 'USD',
        public TransactionStatus $status = TransactionStatus::Authorised,
        public ?string $localId = null,
    ) {
        if (count($this->lines) < 2) {
            throw new InvalidPayloadException('A journal entry needs at least two lines to balance.');
        }

        $out = $this->imbalance();

        if ($out !== 0) {
            throw new InvalidPayloadException(sprintf(
                'Journal "%s" does not balance: it is out by %d minor units.',
                $this->narration,
                $out,
            ));
        }
    }

    public function entityType(): EntityType
    {
        return EntityType::Journal;
    }

    public function localId(): ?string
    {
        return $this->localId;
    }

    /**
     * How far from zero the lines sum, in minor units. Zero means balanced.
     */
    public function imbalance(): int
    {
        return array_reduce(
            $this->lines,
            fn (int $carry, JournalLine $line): int => $carry + $line->amount->amount,
            0,
        );
    }
}
