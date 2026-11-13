<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One page of experiments, as the spec shows it. Page::toResponse() builds the real body. */
#[OA\Schema(required: ['items', 'next'])]
final readonly class ExperimentSummaryListResponse
{
    /** @param list<ExperimentSummaryRowResponse> $items */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ExperimentSummaryRowResponse::class)))]
        public array $items,

        #[OA\Property(description: 'The URL of the next page, or null on the last page.', format: 'uri', nullable: true)]
        public ?string $next,
    ) {
    }
}
