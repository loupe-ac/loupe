<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ListProjectRowsCommand;
use App\Module\Project\Command\ListProjectRowsHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/** The rows of a later page of the projects list, as streams that keep each row in place. */
#[Route(
    '/projects-live/rows',
    name: 'app_projects_rows',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ShowProjectRowsController extends AppController
{
    public function __construct(
        private readonly ListProjectRowsHandler $listRows,
    ) {
    }

    public function __invoke(
        #[MapQueryParameter] string $ids = '',
        #[MapQueryParameter] string $since = '',
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $view = ($this->listRows)(new ListProjectRowsCommand($user, '' === $ids ? [] : explode(',', $ids), $since));

        $html = $this->renderView('@Project/_project_rows.stream.html.twig', [
            'items' => $view->items,
            'goneIds' => $view->goneIds,
            'newerCount' => $view->newerCount,
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
