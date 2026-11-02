<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Command\FindTakenProjectSlugCommand;
use App\Module\Project\Command\FindTakenProjectSlugHandler;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Creates a project for the owner of the credential. The firewall grants the
 * path to agent-scoped tokens alone. The credential must also cover every
 * project of its owner: a credential bound to one project, or one with no
 * project grant, must never reach past what it covers.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[RateLimit('agent_project_create', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[Route(
    '/api/projects',
    name: 'api_project_create',
    methods: ['POST'],
)]
final class CreateProjectController extends AppController
{
    public function __construct(
        private readonly CreateProjectHandler $createProject,
        private readonly FindTakenProjectSlugHandler $findTakenSlug,
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
        if (null === $credential || !$credential->allProjects) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'credential_not_owner_wide', 'The credential does not cover the whole account.');
        }

        $name = $payload->name();
        if ('' === $name) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'name_required', 'The project needs a name.');
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
            if (isset($e->errors['name']) && 'project.error.slug_empty' === $e->errors['name']) {
                throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'name_invalid', 'The project name is not valid.');
            }

            $slug = ($this->findTakenSlug)(new FindTakenProjectSlugCommand($user, $name));

            throw ApiProblemException::refused(JsonResponse::HTTP_CONFLICT, 'name_taken', 'A project with this name exists already.', null === $slug ? [] : ['slug' => $slug]);
        }

        return $this->json([
            'id' => (string) $project->id,
            'slug' => $project->slug,
            'name' => $project->name,
            'domain' => $project->domain,
            'allowedOrigins' => $project->allowedOrigins,
        ], JsonResponse::HTTP_CREATED);
    }
}
