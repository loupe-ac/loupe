<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunOutputLog;
use Symfony\Component\Validator\Constraints as Assert;

/** Text for the output log of a run, and the byte offset where it starts. */
final class AppendWorkerRunOutputRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $bridgeId = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $offset = null,

        #[Assert\Length(max: WorkerRunOutputLog::MAX_APPEND_BYTES, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\NotNull]
        public ?string $text = null,
    ) {
    }
}
