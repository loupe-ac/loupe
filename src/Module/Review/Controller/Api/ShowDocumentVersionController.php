<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\Fields\FieldSelection;
use App\Api\OpenApi\FieldsParameter;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Review\Command\FindDocumentVersionCommand;
use App\Module\Review\Command\FindDocumentVersionHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use App\Module\Review\Service\DocumentApiProblems;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[PublicGet(
    operationId: 'getDocumentVersion',
    summary: 'Read one version of a document',
    tags: ['Versions'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'version', description: 'The version number of the document, counting from 1.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new FieldsParameter(DocumentVersionResponse::FIELDS),
    ],
    responses: [
        new SuccessResponse(200, 'The version, with its Markdown.', DocumentVersionResponse::class),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'document_version_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/versions/{version}',
    name: 'api_document_version_show',
    requirements: ['version' => '[1-9]\d{0,8}'],
    methods: ['GET'],
)]
final class ShowDocumentVersionController extends AppController
{
    public function __construct(
        private readonly FindDocumentVersionHandler $findVersion,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, int $version, Request $request): JsonResponse
    {
        $fields = FieldSelection::fromRequest($request, DocumentVersionResponse::FIELDS);
        $found = ($this->findVersion)(new FindDocumentVersionCommand($document, $version)) ?? throw DocumentApiProblems::versionNotFound();

        return new JsonResponse($fields->keepObject(DocumentVersionResponse::fromVersion($found->version, $found->threadCount)));
    }
}
