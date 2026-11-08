<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunWakeReport;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The wake of each worker process of one session, in the order the processes started. A null
 * entry is a process with no pause before it.
 */
final class ReportSessionWakeRequest
{
    public const int MAX_PROCESSES = 100;

    public function __construct(
        /** @var list<WorkerRunWakeInput|null>|null */
        #[Assert\Count(min: 1, max: self::MAX_PROCESSES)]
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?array $wakes = null,
    ) {
    }

    /** @return list<WorkerRunWakeReport|null> */
    public function wakes(): array
    {
        return array_values(array_map(
            static fn (?WorkerRunWakeInput $wake): ?WorkerRunWakeReport => $wake?->report(),
            $this->wakes ?? [],
        ));
    }
}
