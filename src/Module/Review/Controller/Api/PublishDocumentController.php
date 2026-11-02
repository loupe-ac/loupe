<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use App\Module\Review\Service\DocumentApiProblems;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[PublicPost(
    operationId: 'publishDocument',
    summary: 'Send a draft document to review',
    tags: ['Documents'],
    description: 'A document that is not a draft comes back unchanged, with `published` false. No version is created.',
    parameters: [new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'), new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new SuccessResponse(200, 'The status after the call.', DocumentPublishResponse::class),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(409, ['document_archived']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/publish',
    name: 'api_document_publish',
    methods: ['POST'],
)]
final class PublishDocumentController extends AppController
{
    public function __construct(
        private readonly PublishDocumentHandler $publishDocument,
        private readonly DocumentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document): JsonResponse
    {
        try {
            $published = ($this->publishDocument)(new PublishDocumentCommand($document));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(new DocumentPublishResponse((string) $document->id, $document->status, $published));
    }
}
