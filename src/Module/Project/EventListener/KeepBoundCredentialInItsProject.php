<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Keeps a credential bound to one project inside that project on every
 * `/api/projects/{handle}` route. It answers the 404 a route gives for a
 * project the caller does not own. Refusals spend their own rate limit, because
 * a controller attribute limit only runs after this listener.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final readonly class KeepBoundCredentialInItsProject
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ProjectRepository $projects,
        private Auditor $auditor,

        #[Autowire(service: 'limiter.agent_bound_project_refusals')]
        private RateLimiterFactoryInterface $refusals,
        private CredentialRateLimitKey $key,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $handle = $request->attributes->get('handle');
        $path = $request->getPathInfo();
        if (!\is_string($handle) || !(str_starts_with($path, '/api/projects/') || str_starts_with($path, '/api/v1/bridge/projects/'))) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $boundProjectId = AuthenticatedCredential::of($token)?->projectId;
        $user = $token?->getUser();
        if (null === $boundProjectId || !$user instanceof User) {
            return;
        }

        $project = $this->projects->findOneByIdOrSlugForOwner($handle, $user);
        if (null !== $project && $boundProjectId->equals($project->id)) {
            return;
        }

        if (!$this->refusals->create($this->key->forRequest($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many refused requests. Please slow down.');
        }

        $this->auditor->record(
            'project.agent_route_refused',
            AuditOutcome::Refused,
            [
                'route' => (string) $request->attributes->get('_route'),
                'handle' => $handle,
                'boundProjectId' => $boundProjectId->toRfc4122(),
            ],
            null,
            Auditor::CATEGORY_SECURITY,
        );

        $event->setResponse(new JsonResponse(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND));
    }
}
