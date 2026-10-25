<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** A bus that keeps every message dispatched to it and handles none. */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public private(set) array $messages = [];

    #[\Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function dispatched(string $class): array
    {
        return array_values(array_filter($this->messages, static fn (object $message): bool => $message instanceof $class));
    }
}
