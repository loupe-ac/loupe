<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['reviewId', 'cardId', 'versionNumber', 'conclusion', 'summary', 'findings', 'answered', 'answeredAt', 'createdAt'])]
final readonly class DocumentAgentReviewResponse
{
    /** @param list<DocumentAgentReviewFindingResponse> $findings */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $reviewId,

        #[OA\Property(format: 'uuid')]
        public string $cardId,

        #[OA\Property]
        public int $versionNumber,

        #[OA\Property(enum: ['success', 'failure'])]
        public string $conclusion,

        #[OA\Property]
        public string $summary,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: DocumentAgentReviewFindingResponse::class)))]
        public array $findings,

        #[OA\Property]
        public bool $answered,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $answeredAt,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,
    ) {
    }
}
