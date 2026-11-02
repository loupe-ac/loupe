<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\Fields\FieldSelection;
use App\Api\OpenApi\FieldsParameter;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Review\Command\PageDocumentsCommand;
use App\Module\Review\Command\PageDocumentsHandler;
use App\Module\Review\View\DocumentPageItem;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listDocuments',
    summary: 'List the documents',
    tags: ['Documents'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sort', description: 'The sort order. The default is creation order, oldest first. A leading `-` sorts newest first.', in: 'query', schema: new OA\Schema(type: 'string', default: 'createdAt', enum: ['createdAt', '-createdAt'])),
        new OA\Parameter(name: 'q', description: 'Terms matched against the title and the current content, anywhere inside a word and ignoring case. Quotes, a leading minus and `or` work. With `q` and no `sort`, a document with every word in the title comes first, and each group reads oldest first.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', description: 'Only documents in this state.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['in-review', 'approved', 'changes-requested', 'draft'])),
        new OA\Parameter(name: 'tag', description: 'Only documents with this tag, ignoring case and surrounding spaces.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'series', description: 'Only documents in this series, ignoring case. With no `sort`, they read by their position.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'includeArchived', description: 'Include archived rows, which are left out by default.', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
        new FieldsParameter(DocumentResponse::FIELDS, DocumentResponse::REFUSED_IN_LIST),
    ],
    responses: [
        new SuccessResponse(200, 'One page of documents.', DocumentListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents',
    name: 'api_document_list',
    methods: ['GET'],
)]
final class ListDocumentsController extends AppController
{
    public function __construct(
        private readonly PageDocumentsHandler $pageDocuments,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, Request $request): JsonResponse
    {
        $query = DocumentListQueryParser::fromRequest($request);
        $fields = FieldSelection::fromRequest($request, DocumentResponse::FIELDS, DocumentResponse::REFUSED_IN_LIST);
        $page = ($this->pageDocuments)(new PageDocumentsCommand(
            project: $project,
            query: $query,
            page: PageRequest::fromRequest($request, $query->order->value, $query->filters()),
            links: $fields->isDefault() || $fields->has('references') || $fields->has('referencedBy'),
        ));

        return $page->toResponse($request, static fn (DocumentPageItem $item): array => $fields->keepObject(DocumentRowResponse::fromItem($item)));
    }
}
