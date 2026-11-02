<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Attributes as OA;

/**
 * The constructor of the public operation attributes. It adds the answers every
 * signed-in public route can give, so an operation lists only its own.
 */
trait PublicOperation
{
    /**
     * @param list<string>       $tags
     * @param list<OA\Parameter> $parameters
     * @param list<OA\Response>  $responses  the answers of this operation beyond the standard ones
     */
    public function __construct(
        string $operationId,
        string $summary,
        array $tags,
        ?string $description = null,
        ?array $parameters = null,
        ?OA\RequestBody $requestBody = null,
        array $responses = [],
    ) {
        parent::__construct(
            operationId: $operationId,
            description: $description,
            summary: $summary,
            requestBody: $requestBody,
            tags: $tags,
            parameters: $parameters,
            responses: PublicResponses::withStandard($responses),
        );
    }
}
