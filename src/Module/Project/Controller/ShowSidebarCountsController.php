<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ShowSidebarCountsCommand;
use App\Module\Project\Command\ShowSidebarCountsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/** Replaces the pills of the project sidebar, or answers 204 when the versions of the page are still current. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/sidebar-counts',
    name: 'app_project_sidebar_counts',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowSidebarCountsController extends AppController
{
    public function __construct(
        private readonly ShowSidebarCountsHandler $showCounts,
    ) {
    }

    public function __invoke(Project $project, #[MapQueryParameter] ?string $since = null): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->showCounts)(new ShowSidebarCountsCommand($project, $user, $since));

        $response = $view->unchanged
            ? new Response('', Response::HTTP_NO_CONTENT)
            : new Response(implode("\n", $view->streams), Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        // A reload follows a change, so a cached answer would show the old counts.
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
