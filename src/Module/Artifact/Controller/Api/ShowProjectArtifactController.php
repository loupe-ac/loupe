<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Artifact\Command\DescribeArtifactCommand;
use App\Module\Artifact\Command\DescribeArtifactHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Security\ArtifactVoter;
use App\Module\Artifact\Security\ReachableArtifact;
use App\Module\Artifact\Service\ArtifactApiProblems;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ArtifactVoter::VIEW, subject: 'artifact')]
#[PublicGet(
    operationId: 'getArtifact',
    summary: 'Describe one artifact',
    tags: ['Artifacts'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'artifact', description: 'The artifact id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'versionNumber', description: 'The artifact version to read. It defaults to the latest.', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new SuccessResponse(200, 'The artifact, its versions, and the files of one version.', ArtifactResponse::class),
        new ProblemResponse(404, ['project_not_found', 'artifact_not_found', 'artifact_version_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/artifacts/{artifact}',
    name: 'api_artifact_show',
    methods: ['GET'],
)]
final class ShowProjectArtifactController extends AppController
{
    public function __construct(
        private readonly DescribeArtifactHandler $describeArtifact,
        private readonly ArtifactApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableArtifact] Artifact $artifact): JsonResponse
    {
        try {
            return new JsonResponse(($this->describeArtifact)(new DescribeArtifactCommand($artifact, VersionNumber::fromRequest($request))));
        } catch (DomainErrors) {
            throw $this->problems->versionNotFound();
        }
    }
}
