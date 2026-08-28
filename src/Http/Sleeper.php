<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Http;

/**
 * Waiting, behind an interface so tests do not actually wait.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
