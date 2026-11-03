<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Service\DocumentApiProblems;
use App\Module\Review\Service\DocumentReferenceResolver;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'createDocument',
    summary: 'Create a document',
    tags: ['Documents'],
    description: '`url` is the review page in the app. `Location` names the new document.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateDocumentRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new document.', DocumentCreatedResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['series_ordinal_taken', 'series_name_taken', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents',
    name: 'api_document_create',
    methods: ['POST'],
)]
final class CreateDocumentController extends AppController
{
    public function __construct(
        private readonly CreateDocumentHandler $createDocument,
        private readonly DocumentReferenceResolver $references,
        private readonly DocumentApiProblems $problems,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] CreateDocumentRequest $payload): JsonResponse
    {
        try {
            $document = ($this->createDocument)(new CreateDocumentCommand(
                project: $project,
                title: $payload->title ?? throw new \LogicException('title required after validation'),
                markdown: $payload->markdown ?? throw new \LogicException('markdown required after validation'),
                description: $payload->description(),
                tagNames: $payload->tags,
                references: $this->references->resolve($project, $payload->references),
                seriesName: $payload->series,
                seriesOrdinal: $payload->seriesOrdinal,
                language: $payload->language,
                draft: $payload->draft,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(
            DocumentCreatedResponse::fromDocument($document, $this->links->document((string) $project->id, (string) $document->id)),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_document_show', ['project' => (string) $project->id, 'document' => (string) $document->id], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
