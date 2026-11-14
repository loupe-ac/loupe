<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\GitHub\Service\LivePickerSignIn;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Service\ProjectSetupSkips;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

// Priority 2: after the terms gate (3), so a person who must accept the terms
// first never reaches this redirect.
#[AsEventListener(priority: 2)]
final readonly class RequireProjectSetUpListener
{
    public const string SETUP_ROUTE = 'app_project_setup';

    public const string INTENDED_PATH_SESSION_KEY = 'project_setup.intended_path';

    public const string SKIPPED_SESSION_KEY = ProjectSetupSkips::SESSION_KEY;

    private const string REPOSITORIES_ROUTE = 'app_project_repositories_settings';

    /** Pages the setup page links to or needs while the project is not set up. */
    private const array EXEMPT_ROUTES = [
        self::SETUP_ROUTE,
        'app_project_setup_list',
        'app_github_repositories_start',
        'app_github_app_install',
        'app_github_init_handoff',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ProjectRepository $projects,
        private UrlGeneratorInterface $urlGenerator,
        private LivePickerSignIn $pickerSignIn,
        private ProjectSetupSkips $skips,
    ) {
    }

    /**
     * The raw project id in the route values, or null. A `{id:project}` route stores it under
     * `project` and a `{projectId}` route under `projectId`.
     */
    public static function projectValue(Request $request): ?string
    {
        foreach (['projectId', 'project'] as $name) {
            $value = $request->attributes->get($name);
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/projects/') || !$this->isHtmlNavigation($request)) {
            return;
        }

        $value = self::projectValue($request);
        if (null === $value || !Uuid::isValid($value) || null === $this->tokenStorage->getToken()?->getUser()) {
            return;
        }

        $project = $this->projects->find($value);
        if (!$project instanceof Project || $project->setUp || !$this->authorizationChecker->isGranted(ProjectVoter::VIEW, $project)) {
            return;
        }

        if ($this->isExempt($request, $project) || $this->isSkipped($request, $project)) {
            return;
        }

        if ($request->hasSession()) {
            $request->getSession()->set(self::INTENDED_PATH_SESSION_KEY, $request->getRequestUri());
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate(self::SETUP_ROUTE, ['id' => (string) $project->id])));
    }

    private function isExempt(Request $request, Project $project): bool
    {
        $route = $request->attributes->get('_route');
        if (in_array($route, self::EXEMPT_ROUTES, true)) {
            return true;
        }

        return self::REPOSITORIES_ROUTE === $route && $this->pickerSignIn->existsFor($project->requireId());
    }

    private function isSkipped(Request $request, Project $project): bool
    {
        return $request->hasSession() && $this->skips->isSkipped($request->getSession(), $project);
    }

    private function isHtmlNavigation(Request $request): bool
    {
        if (!$request->isMethodSafe()) {
            return false;
        }

        // A GET that follows a Turbo form post lists the stream type first and html second, and it renders a page.
        $accepted = $request->getAcceptableContentTypes();

        return [] === $accepted || \in_array('text/html', $accepted, true) || \in_array('*/*', $accepted, true);
    }
}
