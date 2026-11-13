<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPut;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Review\Command\SetDocumentHighlightsCommand;
use App\Module\Review\Command\SetDocumentHighlightsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[PublicPut(
    operationId: 'setDocumentHighlights',
    summary: 'Replace the highlights of a document',
    tags: ['Documents'],
    description: 'Highlights tint the passages a reviewer should read first. The call replaces the whole highlight set of the current version, and an empty list clears it. A new version starts with none, so restate them after a revision. A quote that cannot be located is reported in `skipped` and does not fail the call. The route answers 404 `route_not_found` while the instance has `review.highlights.enabled` switched off.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SetDocumentHighlightsRequest::class))),
    responses: [
        new SuccessResponse(200, 'The highlight set after the call.', DocumentHighlightsResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/highlights',
    name: 'api_document_highlights',
    methods: ['PUT'],
)]
final class SetDocumentHighlightsController extends AppController
{
    public function __construct(
        private readonly SetDocumentHighlightsHandler $setHighlights,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, #[MapRequestPayload] SetDocumentHighlightsRequest $body): JsonResponse
    {
        $summary = ($this->setHighlights)(new SetDocumentHighlightsCommand(
            document: $document,
            quotes: array_values($body->quotes),
        ));

        return new JsonResponse(new DocumentHighlightsResponse(
            $summary['highlighted'],
            array_map(static fn (array $skipped): HighlightSkippedResponse => new HighlightSkippedResponse($skipped['quote'], $skipped['reason']), $summary['skipped']),
        ));
    }
}
