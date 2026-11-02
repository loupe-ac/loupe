<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\Fields\FieldSelection;
use App\Api\OpenApi\FieldsParameter;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\CreatedSort;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Review\Command\PageDocumentVersionsCommand;
use App\Module\Review\Command\PageDocumentVersionsHandler;
use App\Module\Review\View\DocumentVersionPageItem;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[PublicGet(
    operationId: 'listDocumentVersions',
    summary: 'List the versions of a document',
    tags: ['Versions'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sort', description: 'The sort order. The default is creation order, oldest first. A leading `-` sorts newest first.', in: 'query', schema: new OA\Schema(type: 'string', default: 'createdAt', enum: ['createdAt', '-createdAt'])),
        new FieldsParameter(DocumentVersionResponse::FIELDS, DocumentVersionResponse::REFUSED_IN_LIST),
    ],
    responses: [
        new SuccessResponse(200, 'One page of versions.', DocumentVersionListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/versions',
    name: 'api_document_version_list',
    methods: ['GET'],
)]
final class ListDocumentVersionsController extends AppController
{
    public function __construct(
        private readonly PageDocumentVersionsHandler $pageVersions,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, Request $request): JsonResponse
    {
        $sort = CreatedSort::fromRequest($request);
        $fields = FieldSelection::fromRequest($request, DocumentVersionResponse::FIELDS, DocumentVersionResponse::REFUSED_IN_LIST);
        $page = ($this->pageVersions)(new PageDocumentVersionsCommand(
            document: $document,
            page: PageRequest::fromRequest($request, $sort->value),
            sort: $sort,
        ));

        return $page->toResponse($request, static fn (DocumentVersionPageItem $item): array => $fields->keepObject(DocumentVersionRowResponse::fromItem($item)));
    }
}
