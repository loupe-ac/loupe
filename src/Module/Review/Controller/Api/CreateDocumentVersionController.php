<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use App\Module\Review\Service\DocumentApiProblems;
use App\Module\Review\Service\DocumentReferenceResolver;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[PublicPost(
    operationId: 'createDocumentVersion',
    summary: 'Submit a revision of a document',
    tags: ['Versions'],
    description: 'Open comments are re-anchored onto the new version. Those whose quoted text no longer appears are flagged orphaned. `Location` names the new version.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateDocumentVersionRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new version, with the comment counts.', DocumentRevisionResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(409, ['series_ordinal_taken', 'series_name_taken', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/versions',
    name: 'api_document_version_create',
    methods: ['POST'],
)]
final class CreateDocumentVersionController extends AppController
{
    public function __construct(
        private readonly ReviseDocumentHandler $reviseDocument,
        private readonly DocumentReferenceResolver $references,
        private readonly DocumentApiProblems $problems,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, #[MapRequestPayload] CreateDocumentVersionRequest $payload): JsonResponse
    {
        try {
            $revision = ($this->reviseDocument)(new ReviseDocumentCommand(
                document: $document,
                markdown: $payload->markdown ?? throw new \LogicException('markdown required after validation'),
                description: $payload->description ?? throw new \LogicException('description required after validation'),
                title: $payload->title,
                references: isset($payload->references) ? $this->references->resolve($document->project, $payload->references) : null,
                seriesName: $payload->series,
                seriesOrdinal: $payload->seriesOrdinal,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(
            new DocumentRevisionResponse($this->links->document((string) $document->project->id, (string) $document->id), $revision['versionNumber'], $revision['carried'], $revision['orphaned']),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_document_version_show', ['project' => (string) $document->project->id, 'document' => (string) $document->id, 'version' => $revision['versionNumber']], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
