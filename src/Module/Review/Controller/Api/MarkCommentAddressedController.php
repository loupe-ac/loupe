<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Module\Review\Command\MarkCommentIdsAddressedCommand;
use App\Module\Review\Command\MarkCommentIdsAddressedHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentComment;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use App\Module\Review\Service\DocumentApiProblems;
use App\Security\CurrentApiKeyActor;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[PublicPatch(
    operationId: 'markDocumentCommentAddressed',
    summary: 'Mark a comment thread addressed',
    tags: ['Review'],
    description: 'Take the root comment of a thread on the current version. A reply gets 422, with `errors[].code` `comment_is_reply`. A thread that is already addressed answers 200, and nothing changes.',
    parameters: [new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'), new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'comment', description: 'The id of a comment from the review, on the current version of the document.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MarkCommentAddressedRequest::class))),
    responses: [
        new SuccessResponse(200, 'The thread is addressed.', CommentStatusResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'comment_not_found', 'route_not_found']),
        new ProblemResponse(409, ['comment_superseded', 'comment_already_resolved', 'comment_deleted']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/comments/{comment}',
    name: 'api_document_comment_mark_addressed',
    methods: ['PATCH'],
)]
final class MarkCommentAddressedController extends AppController
{
    public function __construct(
        private readonly MarkCommentIdsAddressedHandler $markAddressed,
        private readonly CurrentApiKeyActor $apiKeys,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, #[DocumentComment] Comment $comment, #[MapRequestPayload] MarkCommentAddressedRequest $payload): JsonResponse
    {
        $result = ($this->markAddressed)(new MarkCommentIdsAddressedCommand($document->project, [(string) $comment->id], $this->apiKeys->current()));
        foreach ($result->skipped as $skipped) {
            if (null !== $problem = DocumentApiProblems::commentSkipped($skipped['reason'])) {
                throw $problem;
            }
        }

        return new JsonResponse(new CommentStatusResponse((string) $comment->id, $this->links->document((string) $document->project->id, (string) $document->id), 'addressed'));
    }
}
