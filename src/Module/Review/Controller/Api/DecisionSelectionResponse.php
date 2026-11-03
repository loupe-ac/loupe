<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['option', 'index', 'answeredAt', 'answeredAtVersion'])]
final readonly class DecisionSelectionResponse
{
    public function __construct(
        #[OA\Property(description: 'The option as it read when it was chosen.')]
        public string $option,

        #[OA\Property(description: 'The index in `options`, or null when it is no longer offered.', nullable: true)]
        public ?int $index,

        #[OA\Property(format: 'date-time')]
        public string $answeredAt,

        #[OA\Property]
        public int $answeredAtVersion,
    ) {
    }
}
