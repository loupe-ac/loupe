<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use App\Module\Review\Service\DocumentApiProblems;
use App\Module\Review\Service\ReviewWriteGuard;
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
    operationId: 'submitDocumentVerdict',
    summary: 'Record the verdict of the owner',
    tags: ['Review'],
    description: 'An approval moves the card of the document on. A verdict on a draft stops its agent review. A bridge worker is refused. `Location` points at the review, because no route reads one verdict.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SubmitVerdictRequest::class))),
    responses: [
        new SuccessResponse(201, 'The verdict is recorded.', VerdictResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(403, ['credential_not_allowed']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(409, ['verdict_stale', 'verdict_changed', 'decisions_unanswered', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/verdicts',
    name: 'api_document_verdict_submit',
    methods: ['POST'],
)]
final class SubmitVerdictController extends AppController
{
    public function __construct(
        private readonly SubmitReviewHandler $submitReview,
        private readonly ReviewWriteGuard $guard,
        private readonly CurrentApiKeyActor $apiKeys,
        private readonly DocumentApiProblems $problems,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, #[MapRequestPayload] SubmitVerdictRequest $payload): JsonResponse
    {
        $this->guard->refuseWorker($document->project);
        $user = $this->getUser();
        $apiKey = $this->apiKeys->current();

        try {
            $review = ($this->submitReview)(new SubmitReviewCommand(
                reviewer: $user instanceof User ? $user : throw new \LogicException('An API call always authenticates a user.'),
                document: $document,
                verdict: $payload->verdict ?? throw new \LogicException('verdict required after validation'),
                versionNumber: $payload->versionNumber ?? throw new \LogicException('versionNumber required after validation'),
                note: $payload->note,
                expectedReviewId: $payload->expectedVerdictId,
                throughAgent: null === $apiKey,
                apiKey: $apiKey,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(
            new VerdictResponse((string) $review->id, $this->links->document((string) $document->project->id, (string) $document->id), $review->verdict->value, $document->status->value),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_document_review_show', ['project' => (string) $document->project->id, 'document' => (string) $document->id], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
