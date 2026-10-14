<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** A listener that keeps every event it receives. Register the instance with an event dispatcher. */
final class RecordedEvents
{
    /** @var list<object> */
    public array $events = [];

    public function __invoke(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $found[] = $event;
            }
        }

        return $found;
    }
}
