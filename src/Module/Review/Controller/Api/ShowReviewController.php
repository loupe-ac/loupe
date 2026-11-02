<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\Fields\FieldSelection;
use App\Api\OpenApi\FieldsParameter;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Review\Command\ShowReviewCommand;
use App\Module\Review\Command\ShowReviewHandler;
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
    operationId: 'getDocumentReview',
    summary: 'Read the review of the current version',
    tags: ['Review'],
    parameters: [new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'), new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new FieldsParameter(ReviewResponse::FIELDS)],
    responses: [
        new SuccessResponse(200, 'The review state.', ReviewResponse::class, narrowable: true),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/review',
    name: 'api_document_review_show',
    methods: ['GET'],
)]
final class ShowReviewController extends AppController
{
    public function __construct(
        private readonly ShowReviewHandler $showReview,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, Request $request): JsonResponse
    {
        $fields = FieldSelection::fromRequest($request, ReviewResponse::FIELDS);

        return new JsonResponse($fields->keepObject(ReviewResponse::fromPayload(($this->showReview)(new ShowReviewCommand($document)))));
    }
}
