<?php

declare(strict_types=1);

/**
 * Copyright (c) 2021 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/roach-php/roach
 */

namespace RoachPHP\Events;

use PHPUnit\Framework\Assert;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class FakeDispatcher implements EventDispatcherInterface
{
    /**
     * @var Array<string, object[]>
     */
    private array $dispatchedEvents = [];

    /**
     * @var Array<string, callable[]>
     */
    private array $listeners = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $eventName ??= \get_class($event);

        if (isset($this->listeners[$eventName])) {
            foreach ($this->listeners[$eventName] as $listener) {
                $listener($event);
            }
        }

        $this->dispatchedEvents[$eventName][] = $event;

        return $event;
    }

    public function assertDispatched(string $eventName, ?callable $callback = null): void
    {
        Assert::assertArrayHasKey($eventName, $this->dispatchedEvents);

        if (null !== $callback) {
            foreach ($this->dispatchedEvents[$eventName] as $event) {
                if ($callback($event)) {
                    return;
                }
            }

            Assert::fail('Event was not dispatched with correct payload');
        }
    }

    public function assertNotDispatched(string $eventName): void
    {
        Assert::assertArrayNotHasKey($eventName, $this->dispatchedEvents);
    }

    public function listen(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }
}