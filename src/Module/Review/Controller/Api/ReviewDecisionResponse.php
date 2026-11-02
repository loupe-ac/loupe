<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Module\Review\Command\ShowReviewHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * @phpstan-import-type ReviewPayload from ShowReviewHandler
 */
#[OA\Schema(required: ['id', 'type', 'options', 'selected', 'selectedIndex', 'answeredAt', 'answeredAtVersion', 'note', 'updatedAt', 'throughAgent', 'selections', 'throughApiKey'])]
final readonly class ReviewDecisionResponse
{
    /**
     * @param list<string>                    $options
     * @param list<DecisionSelectionResponse> $selections
     */
    public function __construct(
        #[OA\Property]
        public string $id,

        #[OA\Property(enum: ['single', 'multiple'])]
        public string $type,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $options,

        #[OA\Property(description: 'For a single-choice block. Null on a multiple-choice block.', nullable: true)]
        public ?string $selected,

        #[OA\Property(nullable: true)]
        public ?int $selectedIndex,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $answeredAt,

        #[OA\Property(nullable: true)]
        public ?int $answeredAtVersion,

        #[OA\Property(nullable: true)]
        public ?string $note,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $updatedAt,

        #[OA\Property(description: 'An agent recorded the answer for the owner.')]
        public bool $throughAgent,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: DecisionSelectionResponse::class)))]
        public array $selections,

        #[OA\Property(description: 'An API key recorded the answer for the owner.')]
        public bool $throughApiKey,
    ) {
    }
}
