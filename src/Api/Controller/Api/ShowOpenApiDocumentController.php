<?php

declare(strict_types=1);

namespace App\Api\Controller\Api;

use App\Api\Command\ShowOpenApiDocumentCommand;
use App\Api\Command\ShowOpenApiDocumentHandler;
use App\Controller\AppController;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the committed public spec as it is. The route reads a file, so it needs
 * neither the generator nor swagger-php at run time. Needs no sign-in.
 */
#[OA\Get(operationId: 'getOpenApiDocument', description: 'No sign-in is needed.', summary: 'Read this OpenAPI document', security: [], tags: ['Meta'], responses: [
    new OA\Response(response: 200, description: 'The public v1 specification, as JSON.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
    new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new OA\Response(response: 429, description: 'Refused: `rate_limited`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
])]
#[Route(
    '/api/v1/openapi.json',
    name: 'api_v1_openapi',
    methods: ['GET'],
)]
final class ShowOpenApiDocumentController extends AppController
{
    public function __construct(
        private readonly ShowOpenApiDocumentHandler $showOpenApiDocument,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response(($this->showOpenApiDocument)(new ShowOpenApiDocumentCommand()), Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
