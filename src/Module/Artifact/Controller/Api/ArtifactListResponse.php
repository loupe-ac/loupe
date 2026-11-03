<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One page of artifacts, as the spec shows it. Page::toResponse() builds the real body. */
#[OA\Schema(required: ['items', 'next'])]
final readonly class ArtifactListResponse
{
    /** @param list<ArtifactRowResponse|ArtifactResponse|array<string, mixed>> $items */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(anyOf: [
            new OA\Schema(ref: new Model(type: ArtifactRowResponse::class)),
            new OA\Schema(ref: new Model(type: ArtifactResponse::class)),
            new OA\Schema(ref: '#/components/schemas/'.ArtifactResponse::PARTIAL),
        ]))]
        public array $items,

        #[OA\Property(description: 'The URL of the next page, or null on the last page.', format: 'uri', nullable: true)]
        public ?string $next,
    ) {
    }
}
