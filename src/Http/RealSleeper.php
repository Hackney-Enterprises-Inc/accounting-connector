<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Http;

final class RealSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }
}
