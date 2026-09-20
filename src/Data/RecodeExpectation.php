<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Data;

use DateTimeImmutable;

/**
 * What a recode decision was made against, so the write can refuse a stale one.
 *
 * A host decides to recode from a read it made a moment ago, or from a mirror row
 * that is minutes or hours old. The connector reads again immediately before it
 * writes. Between the two reads a bookkeeper can have coded the line to something
 * deliberate, and laying the host's coding over that is exactly the overwrite the
 * whole matching feature exists to avoid. So the decision travels with the write:
 * the codes it was made against and the provider's own modification stamp. If the
 * connector's read disagrees on either, nothing is sent.
 *
 * Built with {@see self::from()} off the transaction the host read; compared with
 * {@see self::differences()} against the one the connector read.
 */
final readonly class RecodeExpectation
{
    /**
     * @param  array<string, string|null>  $accountCodesByLine  Line id (or `#index`) to account code.
     */
    public function __construct(
        public array $accountCodesByLine,
        public ?DateTimeImmutable $updatedDateUtc = null,
    ) {}

    public static function from(BankTransactionData $transaction): self
    {
        return new self(
            accountCodesByLine: $transaction->accountCodesByLine(),
            updatedDateUtc: $transaction->updatedDateUtc,
        );
    }

    /**
     * Every way the fresh transaction differs from what was expected, in words.
     *
     * Empty means the decision still stands. The modification stamp is compared
     * only when both sides have one: a fixture or a provider that omits it cannot
     * be held to it.
     *
     * @return array<int, string>
     */
    public function differences(BankTransactionData $fresh): array
    {
        $differences = [];
        $actual = $fresh->accountCodesByLine();

        foreach ($this->accountCodesByLine as $line => $expected) {
            if (! array_key_exists($line, $actual)) {
                $differences[] = "line {$line} is no longer on the transaction";

                continue;
            }

            if ($actual[$line] !== $expected) {
                $differences[] = sprintf(
                    'line %s is coded to %s, not %s',
                    $line,
                    $actual[$line] ?? 'nothing',
                    $expected ?? 'nothing',
                );
            }
        }

        foreach (array_diff_key($actual, $this->accountCodesByLine) as $line => $code) {
            $differences[] = sprintf('line %s was added, coded to %s', $line, $code ?? 'nothing');
        }

        if ($this->updatedDateUtc !== null
            && $fresh->updatedDateUtc !== null
            && $fresh->updatedDateUtc->format('U.u') !== $this->updatedDateUtc->format('U.u')) {
            $differences[] = sprintf(
                'modified at %s, expected %s',
                $fresh->updatedDateUtc->format(DATE_ATOM),
                $this->updatedDateUtc->format(DATE_ATOM),
            );
        }

        return $differences;
    }

    public function matches(BankTransactionData $fresh): bool
    {
        return $this->differences($fresh) === [];
    }
}
