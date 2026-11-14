<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the live signals queued while a database transaction is open. The
 * driver middleware reports the outermost begin, commit and rollback. A commit
 * releases the held signals to the publisher, and a rollback drops them.
 */
final class LiveUpdateTransactionBuffer implements ResetInterface
{
    private int $open = 0;

    /** @var list<array{topic: string, data: string}> */
    private array $held = [];

    /** @var list<array{topic: string, data: string}> */
    private array $committed = [];

    public function isOpen(): bool
    {
        return $this->open > 0;
    }

    public function hold(string $topic, string $data): void
    {
        $this->held[] = ['topic' => $topic, 'data' => $data];
    }

    public function begin(): void
    {
        ++$this->open;
    }

    public function commit(): void
    {
        if ($this->open < 1) {
            return;
        }

        if (0 === --$this->open) {
            array_push($this->committed, ...$this->held);
            $this->held = [];
        }
    }

    public function rollBack(): void
    {
        if ($this->open < 1) {
            return;
        }

        if (0 === --$this->open) {
            $this->held = [];
        }
    }

    /**
     * The signals whose transaction committed since the last call.
     *
     * @return list<array{topic: string, data: string}>
     */
    public function takeCommitted(): array
    {
        $committed = $this->committed;
        $this->committed = [];

        return $committed;
    }

    #[\Override]
    public function reset(): void
    {
        $this->open = 0;
        $this->held = [];
        $this->committed = [];
    }
}
