<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Service\ProjectApiProblems;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Creates a project for the owner of the credential. The credential must also
 * cover every project of its owner: a credential bound to one project, or one
 * with no project grant, must never reach past what it covers.
 *
 * The old path stays for one release, because the CLI calls it until the next
 * server release. The exception listener gives it the old flat error body.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[PublicPost(
    operationId: 'createProject',
    summary: 'Create a project',
    tags: ['Projects'],
    description: 'The credential must cover every project of its owner. The search language starts at the instance default. PATCH sets `description` and `searchLanguage`.',
    parameters: [new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateProjectRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new project.', ProjectResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(403, ['credential_not_owner_wide', 'credential_not_allowed']),
        new ProblemResponse(409, ['name_taken', 'idempotency_key_reused', 'idempotency_in_progress'], 'NameTakenProblem'),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['name_required', 'name_invalid', 'validation_failed'], 'ValidationProblem'),
    ],
)]
#[RateLimit('agent_project_create', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/v1/projects',
    name: 'api_project_create',
    methods: ['POST'],
)]
#[Route(
    '/api/projects',
    name: 'api_legacy_project_create',
    methods: ['POST'],
)]
final class CreateProjectController extends AppController
{
    public function __construct(
        private readonly CreateProjectHandler $createProject,
        private readonly ProjectApiProblems $problems,
        private readonly TokenStorageInterface $tokenStorage,
        public readonly CredentialRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(#[MapRequestPayload] CreateProjectRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Project creation reached without an authenticated User.');
        }

        $credential = AuthenticatedCredential::of($this->tokenStorage->getToken());
        if (null === $credential || !$credential->reachesEveryProject()) {
            throw new ApiProblemException(new ApiProblem(403, 'credential_not_owner_wide', 'Credential not owner-wide', 'Only a credential that covers every project of the owner can create a project.'));
        }

        $name = $payload->name();
        if ('' === $name) {
            throw new ApiProblemException(new ApiProblem(422, 'name_required', 'Name required', 'Give a name, or a remote that names the repository.'));
        }

        try {
            $project = ($this->createProject)(new CreateProjectCommand(
                owner: $user,
                name: $name,
                domain: $payload->domain(),
                searchLanguage: SearchLanguage::DEFAULT,
                allowedOrigins: $payload->origins(),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->forCreate($e, $user, $name);
        }

        return new JsonResponse(
            ProjectResponse::fromProject($project),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_project_show', ['project' => (string) $project->id], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
