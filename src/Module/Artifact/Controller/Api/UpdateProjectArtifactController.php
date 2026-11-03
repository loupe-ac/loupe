<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Artifact\Command\DescribeArtifactCommand;
use App\Module\Artifact\Command\DescribeArtifactHandler;
use App\Module\Artifact\Command\SetArtifactArchivedCommand;
use App\Module\Artifact\Command\SetArtifactArchivedHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Security\ArtifactVoter;
use App\Module\Artifact\Security\ReachableArtifact;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ArtifactVoter::MANAGE, subject: 'artifact')]
#[PublicPatch(
    operationId: 'updateArtifact',
    summary: 'Archive or restore an artifact',
    tags: ['Artifacts'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'artifact', description: 'The artifact id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateArtifactRequest::class))),
    responses: [
        new SuccessResponse(200, 'The artifact after the change.', ArtifactResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'artifact_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/artifacts/{artifact}',
    name: 'api_artifact_update',
    methods: ['PATCH'],
)]
final class UpdateProjectArtifactController extends AppController
{
    public function __construct(
        private readonly SetArtifactArchivedHandler $setArchived,
        private readonly DescribeArtifactHandler $describeArtifact,
        private readonly PublicArtifactAnswer $answer,
    ) {
    }

    public function __invoke(#[ReachableArtifact] Artifact $artifact, #[MapRequestPayload] UpdateArtifactRequest $payload): JsonResponse
    {
        ($this->setArchived)(new SetArtifactArchivedCommand($artifact, $payload->archived ?? throw new \LogicException('archived required after validation')));

        return new JsonResponse($this->answer->of(($this->describeArtifact)(new DescribeArtifactCommand($artifact, null)), (string) $artifact->project->id));
    }
}
