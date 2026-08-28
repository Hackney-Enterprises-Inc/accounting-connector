<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Laravel;

use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * PSR-14 over Laravel's dispatcher.
 *
 * Laravel's own dispatcher does not implement PSR-14, and the package core is
 * framework-free and types against PSR-14. This is the whole adapter.
 */
final class LaravelEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly LaravelDispatcher $events,
    ) {}

    public function dispatch(object $event): object
    {
        $this->events->dispatch($event);

        return $event;
    }
}
