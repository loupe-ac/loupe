<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkerRunWakeReport;
use Symfony\Component\Uid\Uuid;

/** The wake of each worker process of a session in start order. A null entry has no pause before it. */
final readonly class ReportSessionWakeCommand
{
    /** @param list<WorkerRunWakeReport|null> $wakes */
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $sessionId,
        public array $wakes,
    ) {
    }
}
