<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['index', 'severity', 'title', 'body', 'needsPerson', 'answer'])]
final readonly class DocumentAgentReviewFindingResponse
{
    /** @param array{answer: string, reason: ?string}|null $answer */
    public function __construct(
        #[OA\Property]
        public int $index,

        #[OA\Property(enum: ['important', 'nit', 'pre-existing'])]
        public string $severity,

        #[OA\Property]
        public string $title,

        #[OA\Property]
        public string $body,

        #[OA\Property]
        public bool $needsPerson,

        #[OA\Property(required: ['answer', 'reason'], properties: [new OA\Property(property: 'answer', type: 'string'), new OA\Property(property: 'reason', type: 'string', nullable: true)], type: 'object', nullable: true)]
        public ?array $answer,
    ) {
    }
}
