<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Http;

/**
 * Records what it was asked to wait for, and does not wait. For tests.
 */
final class NullSleeper implements Sleeper
{
    /** @var array<int, float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
    }
}
