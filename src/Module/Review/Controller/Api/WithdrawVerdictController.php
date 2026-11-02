<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Security\ProjectDocument;
use App\Module\Review\Service\DocumentApiProblems;
use App\Module\Review\Service\ReviewWriteGuard;
use App\Security\CurrentApiKeyActor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::CONTRIBUTE, subject: 'document')]
#[PublicDelete(
    operationId: 'withdrawDocumentVerdict',
    summary: 'Withdraw the newest verdict for the owner',
    tags: ['Review'],
    description: 'Call this only after the owner asked for it. A bridge worker is refused.',
    parameters: [new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'), new OA\Parameter(name: 'document', description: 'The document id.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'verdict', description: 'The `verdictId` from the review. A newer verdict is refused.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'versionNumber', description: 'The version you showed the owner. A newer version is refused.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
    responses: [
        new SuccessResponse(200, 'The verdict is withdrawn.', VerdictWithdrawResponse::class),
        new ProblemResponse(403, ['credential_not_allowed']),
        new ProblemResponse(404, ['project_not_found', 'document_not_found', 'route_not_found']),
        new ProblemResponse(409, ['verdict_stale', 'verdict_changed', 'verdict_none', 'verdict_already_withdrawn']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/documents/{document}/verdicts/{verdict}',
    name: 'api_document_verdict_withdraw',
    methods: ['DELETE'],
)]
final class WithdrawVerdictController extends AppController
{
    public function __construct(
        private readonly UndoVerdictHandler $undoVerdict,
        private readonly ReviewWriteGuard $guard,
        private readonly CurrentApiKeyActor $apiKeys,
        private readonly DocumentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ProjectDocument] Document $document, string $verdict, Request $request): JsonResponse
    {
        $this->guard->refuseWorker($document->project);
        $versionNumber = VersionNumberQuery::fromRequest($request);
        $user = $this->getUser();
        $apiKey = $this->apiKeys->current();

        try {
            $withdrawal = ($this->undoVerdict)(new UndoVerdictCommand(
                document: $document,
                actor: $user instanceof User ? $user : throw new \LogicException('An API call always authenticates a user.'),
                reviewId: $verdict,
                expectedVersionNumber: $versionNumber,
                throughAgent: null === $apiKey,
                apiKey: $apiKey,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse(new VerdictWithdrawResponse((string) $withdrawal->id, $document->status->value));
    }
}
