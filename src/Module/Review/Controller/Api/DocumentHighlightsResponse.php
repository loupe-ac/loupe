<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The highlight set after a call. */
#[OA\Schema(required: ['highlighted', 'skipped'])]
final readonly class DocumentHighlightsResponse
{
    /**
     * @param list<string>                   $highlighted
     * @param list<HighlightSkippedResponse> $skipped
     */
    public function __construct(
        #[OA\Property(description: 'The quotes that now carry a highlight, trimmed, in request order. A quote that occurs more than once lands on its first occurrence.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $highlighted,

        #[OA\Property(description: 'The quotes that the call skipped, with the reason. A skipped quote is not an error.', type: 'array', items: new OA\Items(ref: new Model(type: HighlightSkippedResponse::class)))]
        public array $skipped,
    ) {
    }
}
