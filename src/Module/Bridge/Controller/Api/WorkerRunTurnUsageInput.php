<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The final usage of the first assistant turn after a resume. */
final class WorkerRunTurnUsageInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $inputTokens = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $cacheReadTokens = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $cacheWriteTokens = null,
    ) {
    }
}
