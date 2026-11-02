<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** The answer of the end of a workflow pause. */
#[OA\Schema(required: ['cardId', 'outcome', 'kind', 'reason', 'ruleId'])]
final readonly class CardPauseReleaseResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $cardId,

        #[OA\Property(enum: ['released'])]
        public string $outcome,

        #[OA\Property(description: 'The kind of the pause that ended.', enum: ['rule', 'retries', 'work-limit', 'work-timeout', 'work-stopped'])]
        public string $kind,
        public string $reason,
        public string $ruleId,
    ) {
    }
}
