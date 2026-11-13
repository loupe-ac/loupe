<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One page of host samples, as the spec shows it. Page::toResponse() builds the real body. */
#[OA\Schema(required: ['items', 'next', 'note'])]
final readonly class WorkerRunHostSampleListResponse
{
    /** @param list<WorkerRunHostSampleResponse> $items */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: WorkerRunHostSampleResponse::class)))]
        public array $items,

        #[OA\Property(description: 'The URL of the next page, or null on the last page.', format: 'uri', nullable: true)]
        public ?string $next,

        #[OA\Property(description: 'Why the list is empty when the run has no bridge or no start, and null otherwise.', nullable: true)]
        public ?string $note,
    ) {
    }
}
