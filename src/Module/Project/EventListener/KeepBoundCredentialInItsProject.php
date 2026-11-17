<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Api\Problem\ApiProblem;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Keeps a credential bound to one project inside that project on every route
 * whose access rule admits the agent role, whatever its path. A route that names
 * another project answers 404. A route that names none is refused with 403,
 * except the project list, which lists the bound project only. Refusals spend
 * their own rate limit, because a controller limit only runs after this listener.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final readonly class KeepBoundCredentialInItsProject
{
    private const string AGENT_ROLE = 'ROLE_API_AGENT';
    private const string PROJECT_LIST_ROUTE = 'api_projects';

    /** Routes that name their project in the X-Loupe-Project header. */
    private const array PROJECT_HEADER_ROUTES = ['api_interactive_session_card_run'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ProjectRepository $projects,
        private Auditor $auditor,

        #[Autowire(service: 'limiter.agent_bound_project_refusals')]
        private RateLimiterFactoryInterface $refusals,
        private CredentialRateLimitKey $key,

        #[Autowire(service: 'security.access_map')]
        private AccessMapInterface $accessMap,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $boundProjectId = AuthenticatedCredential::of($token)?->projectId;
        $user = $token?->getUser();
        $request = $event->getRequest();
        if (null === $boundProjectId || !$user instanceof User || !$this->isAgentRoute($request)) {
            return;
        }

        $route = (string) $request->attributes->get('_route');
        $named = $this->namedProject($request, $route);
        if (null === $named) {
            if (self::PROJECT_LIST_ROUTE === $route || $this->namesProjectInHeader($route)) {
                return;
            }

            $this->refuse($event, $boundProjectId, 'not_owner_wide', new ApiProblem(
                Response::HTTP_FORBIDDEN,
                'credential_not_owner_wide',
                'Credential not owner-wide',
                'This route needs a credential that covers every project of the owner. Connect again with every project.',
            ));

            return;
        }

        $project = $this->projects->findOneByIdOrSlugForOwner($named, $user);
        if (null !== $project && $boundProjectId->equals($project->id)) {
            return;
        }

        $this->refuse($event, $boundProjectId, 'other_project', new ApiProblem(
            Response::HTTP_NOT_FOUND,
            'project_not_found',
            'Project not found',
            'No project answers this handle.',
        ), $named);
    }

    private function isAgentRoute(Request $request): bool
    {
        $roles = $this->accessMap->getPatterns($request)[0] ?? null;

        return \is_array($roles) && \in_array(self::AGENT_ROLE, $roles, true);
    }

    private function namesProjectInHeader(string $route): bool
    {
        return \in_array($route, self::PROJECT_HEADER_ROUTES, true);
    }

    private function namedProject(Request $request, string $route): ?string
    {
        if ($this->namesProjectInHeader($route)) {
            $header = trim((string) $request->headers->get(AuthenticatedProjectResolver::PROJECT_HEADER));

            return '' === $header ? null : $header;
        }

        $handle = $request->attributes->get('handle');

        return \is_string($handle) ? $handle : null;
    }

    private function refuse(RequestEvent $event, Uuid $boundProjectId, string $reason, ApiProblem $problem, ?string $handle = null): void
    {
        $request = $event->getRequest();
        if (!$this->refusals->create($this->key->forRequest($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many refused requests. Please slow down.');
        }

        $context = [
            'route' => (string) $request->attributes->get('_route'),
            'boundProjectId' => $boundProjectId->toRfc4122(),
            'reason' => $reason,
        ];
        if (null !== $handle) {
            $context['handle'] = $handle;
        }

        $this->auditor->record(
            'project.agent_route_refused',
            AuditOutcome::Refused,
            $context,
            null,
            Auditor::CATEGORY_SECURITY,
        );

        $event->setResponse($problem->toResponse($request->getPathInfo()));
    }
}
