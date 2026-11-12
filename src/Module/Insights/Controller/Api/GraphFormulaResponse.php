<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The formula of a formula graph. */
#[OA\Schema(required: ['rows', 'formula', 'format'])]
final readonly class GraphFormulaResponse
{
    /** @param list<GraphRowResponse> $rows */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: GraphRowResponse::class)))]
        public array $rows,

        #[OA\Property(description: 'The formula over the letters of the rows, such as `A / B`.')]
        public string $formula,

        #[OA\Property(description: '`auto`, `number`, `percent`, `money` or `duration`.')]
        public string $format,
    ) {
    }
}
