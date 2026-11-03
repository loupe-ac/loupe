<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPut;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[PublicPut(
    operationId: 'answerDocumentDecision',
    summary: 'Record the answer of the owner to a decision',
    tags: ['Review'],
    description: 'Call this only after the owner gave the answer. A newer version than `versionNumber` is refused. A bridge worker is refused.',
    parameters: [new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'), new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'decision', description: 'The id that the document declared in the fence of the decision block.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: AnswerDecisionRequest::class))),
    responses: [
        new SuccessResponse(200, 'Whether the answer changed.', DecisionResultResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(403, ['credential_not_allowed']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'decision_not_found', 'route_not_found']),
        new ProblemResponse(409, ['decision_stale']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/decisions/{decision}',
    name: 'api_document_decision_answer',
    methods: ['PUT'],
)]
final class AnswerDecisionController extends AppController
{
    public function __construct(
        private readonly SaveDecisionAnswerHandler $saveAnswer,
        private readonly ReviewWriteGuard $guard,
        private readonly CurrentApiKeyActor $apiKeys,
        private readonly DocumentApiProblems $problems,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, string $decision, #[MapRequestPayload] AnswerDecisionRequest $payload): JsonResponse
    {
        $this->guard->refuseWorker($document->project);
        $user = $this->getUser();
        $apiKey = $this->apiKeys->current();

        try {
            $result = ($this->saveAnswer)(new SaveDecisionAnswerCommand(
                document: $document,
                decisionId: $decision,
                displayedVersionNumber: $payload->versionNumber ?? throw new \LogicException('versionNumber required after validation'),
                optionIndexes: array_values($payload->optionIndexes),
                note: $payload->note,
                clear: false,
                answeredBy: $user instanceof User ? $user : throw new \LogicException('An API call always authenticates a user.'),
                throughAgent: null === $apiKey,
                requireLatestVersion: true,
                keepNote: null === $payload->note,
                apiKey: $apiKey,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(new DecisionResultResponse($decision, $this->links->document((string) $document->project->id, (string) $document->id), $result->changed, $result->cleared));
    }
}
