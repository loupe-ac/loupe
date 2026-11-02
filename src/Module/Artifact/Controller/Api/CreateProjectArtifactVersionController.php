<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\EventListener\RenderApiProblemOnException;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Artifact\Command\StoreArtifactVersionCommand;
use App\Module\Artifact\Command\StoreArtifactVersionHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Security\ArtifactVoter;
use App\Module\Artifact\Security\ReachableArtifact;
use App\Module\Artifact\Service\ArtifactApiProblems;
use App\Module\Artifact\Service\ArtifactUploadReader;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Stores a new version of an artifact from a multipart request. */
#[IsGranted(ArtifactVoter::MANAGE, subject: 'artifact')]
#[PublicPost(
    operationId: 'createArtifactVersion',
    summary: 'Store a new version of an artifact',
    tags: ['Artifacts'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'artifact', description: 'The artifact id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
        mediaType: 'multipart/form-data',
        schema: new OA\Schema(ref: new Model(type: ArtifactVersionUploadRequest::class)),
        encoding: [new OA\Encoding(property: 'paths', style: 'form', explode: true), new OA\Encoding(property: 'files', contentType: 'application/octet-stream')],
    )),
    responses: [
        new SuccessResponse(201, 'The stored version.', ArtifactStoredResponse::class, location: true),
        new ProblemResponse(404, ['project_not_found', 'artifact_not_found', 'route_not_found']),
        new ProblemResponse(409, ['idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/artifacts/{artifact}/versions',
    name: 'api_artifact_version_create',
    defaults: [RenderApiProblemOnException::BODY_MEDIA_TYPE_ATTRIBUTE => 'multipart/form-data'],
    methods: ['POST'],
)]
final class CreateProjectArtifactVersionController extends AppController
{
    public function __construct(
        private readonly StoreArtifactVersionHandler $storeVersion,
        private readonly ArtifactUploadReader $uploads,
        private readonly ArtifactApiProblems $problems,
    ) {
    }

    public function __invoke(
        #[ReachableArtifact] Artifact $artifact,
        #[MapRequestPayload(acceptFormat: 'form')] ArtifactVersionUploadRequest $payload,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The artifact version create reached without an authenticated User.');
        }

        $inputs = $this->uploads->read($payload->paths, $payload->files, $payload->card);

        try {
            $version = ($this->storeVersion)(new StoreArtifactVersionCommand(
                owner: $user,
                handle: (string) $artifact->project->id,
                artifactId: (string) $artifact->id,
                name: null,
                sourceLink: null,
                sessionId: null,
                files: $inputs,
                card: $payload->card,
                notes: $payload->notes,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->forStore($e);
        }

        return new JsonResponse(
            ArtifactStoredResponse::fromVersion($version),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_artifact_show', ['project' => (string) $artifact->project->id, 'artifact' => (string) $artifact->id, 'versionNumber' => $version->number], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
