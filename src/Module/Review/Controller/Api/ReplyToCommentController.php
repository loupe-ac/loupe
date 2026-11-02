<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\ReplyToCommentAsAgentCommand;
use App\Module\Review\Command\ReplyToCommentAsAgentHandler;
use App\Module\Review\Command\StaleCommentVersion;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[PublicPost(
    operationId: 'replyToDocumentComment',
    summary: 'Reply to a comment thread',
    tags: ['Review'],
    description: 'Take a comment id from the current review. A revision makes new comment rows, so reply before you revise, or read the review again after it. `Location` points at the review, because no route reads one comment.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'comment', description: 'The id of a comment from the review, on the current version of the document.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReplyToCommentRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new reply.', CommentReplyResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'comment_not_found', 'route_not_found']),
        new ProblemResponse(409, ['comment_version_stale', 'comment_deleted', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/comments/{comment}/replies',
    name: 'api_document_comment_reply',
    methods: ['POST'],
)]
final class ReplyToCommentController extends AppController
{
    public function __construct(
        private readonly ReplyToCommentAsAgentHandler $replyAsAgent,
        private readonly CurrentApiKeyActor $apiKeys,
        private readonly DocumentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, #[DocumentComment] Comment $comment, #[MapRequestPayload] ReplyToCommentRequest $payload): JsonResponse
    {
        try {
            $reply = ($this->replyAsAgent)(new ReplyToCommentAsAgentCommand(
                parent: $comment,
                body: $payload->body ?? throw new \LogicException('body required after validation'),
                document: $document,
                apiKey: $this->apiKeys->current(),
            ));
        } catch (StaleCommentVersion) {
            throw DocumentApiProblems::commentVersionStale();
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(
            new CommentReplyResponse((string) $reply->id),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_document_review_show', ['project' => (string) $document->project->id, 'document' => (string) $document->id], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
