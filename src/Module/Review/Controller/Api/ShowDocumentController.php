<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Review\Command\ShowDocumentDataCommand;
use App\Module\Review\Command\ShowDocumentDataHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[PublicGet(
    operationId: 'getDocument',
    summary: 'Read a document',
    tags: ['Documents'],
    parameters: [new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'), new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new SuccessResponse(200, 'The document, with its current Markdown.', DocumentResponse::class),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}',
    name: 'api_document_show',
    methods: ['GET'],
)]
final class ShowDocumentController extends AppController
{
    public function __construct(
        private readonly ShowDocumentDataHandler $showDocumentData,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document): JsonResponse
    {
        return new JsonResponse(DocumentResponse::fromPayload(($this->showDocumentData)(new ShowDocumentDataCommand($document)), $document));
    }
}
