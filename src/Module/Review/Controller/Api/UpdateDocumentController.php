<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\ShowDocumentDataCommand;
use App\Module\Review\Command\ShowDocumentDataHandler;
use App\Module\Review\Command\UpdateDocumentCommand;
use App\Module\Review\Command\UpdateDocumentHandler;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[PublicPatch(
    operationId: 'updateDocument',
    summary: 'Change the metadata of a document',
    tags: ['Documents'],
    description: 'The server checks every field before it writes one, so a field it refuses changes nothing. Use `POST .../versions` to change the Markdown.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateDocumentRequest::class))),
    responses: [
        new SuccessResponse(200, 'The document after the change.', DocumentResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(409, ['series_ordinal_taken', 'series_name_taken']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}',
    name: 'api_document_update',
    methods: ['PATCH'],
)]
final class UpdateDocumentController extends AppController
{
    public function __construct(
        private readonly UpdateDocumentHandler $updateDocument,
        private readonly ShowDocumentDataHandler $showDocumentData,
        private readonly DocumentReferenceResolver $references,
        private readonly DocumentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, #[MapRequestPayload] UpdateDocumentRequest $payload): JsonResponse
    {
        try {
            ($this->updateDocument)(new UpdateDocumentCommand(
                document: $document,
                title: $payload->title ?? null,
                tagNames: $payload->tags ?? null,
                references: isset($payload->references) ? $this->references->resolve($document->project, $payload->references) : null,
                placesInSeries: $payload->placesInSeries(),
                seriesName: $payload->series,
                seriesOrdinal: $payload->seriesOrdinal,
                archived: $payload->archived ?? null,
                archiveReason: $payload->archiveReason ?? null,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(DocumentResponse::fromPayload(($this->showDocumentData)(new ShowDocumentDataCommand($document)), $document));
    }
}
