<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use OpenApi\Attributes as OA;

/** One proposal of an analysis. */
#[OA\Schema(required: ['id', 'kind', 'title', 'body', 'payload', 'estimatedSaving', 'state', 'dismissReason', 'cardId'])]
final readonly class AnalysisProposalResponse
{
    /** @param array<mixed>|null $payload */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(enum: ['card', 'bucket-rule'])]
        public string $kind,

        #[OA\Property]
        public string $title,

        #[OA\Property]
        public string $body,

        #[OA\Property(description: 'What the proposal makes when it is accepted: the card fields, or the bucket rule fields.', type: 'object', nullable: true)]
        public ?array $payload,

        #[OA\Property(nullable: true)]
        public ?string $estimatedSaving,

        #[OA\Property(enum: ['proposed', 'created', 'dismissed'])]
        public string $state,

        #[OA\Property(nullable: true)]
        public ?string $dismissReason,

        #[OA\Property(description: 'The card the proposal created.', format: 'uuid', nullable: true)]
        public ?string $cardId,
    ) {
    }
}
