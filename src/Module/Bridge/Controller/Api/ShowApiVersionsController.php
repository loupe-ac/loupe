<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\RateLimitedResponse;
use App\Api\Problem\ApiPaths;
use App\Controller\AppController;
use App\Module\Bridge\Service\CliCompatibility;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tells a client which API versions this server answers and which CLI versions it
 * works with. The bridge reads it before its first call, so a server that is too new
 * or too old for the binary shows as one sentence and not as a failed request.
 * Needs no sign-in.
 */
#[OA\Get(operationId: 'listApiVersions', description: 'No sign-in is needed.', summary: 'List the API versions of this instance', security: [], tags: ['Meta'], responses: [
    new OA\Response(response: 200, description: 'The versions.', content: new OA\JsonContent(ref: '#/components/schemas/ApiVersions')),
    new OA\Response(response: 405, description: 'Refused: `method_not_allowed`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
    new RateLimitedResponse(),
])]
#[Route(
    '/api/versions',
    name: 'api_versions',
    methods: ['GET'],
)]
final class ShowApiVersionsController extends AppController
{
    public const string STATUS = 'beta';

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'versions' => array_map(
                static fn (string $version): array => ['version' => $version, 'status' => self::STATUS],
                ApiPaths::SERVED_VERSIONS,
            ),
            'cliRange' => CliCompatibility::RANGE,
        ]);
    }
}
