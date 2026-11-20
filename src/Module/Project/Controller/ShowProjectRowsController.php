<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ListProjectRowsCommand;
use App\Module\Project\Command\ListProjectRowsHandler;
use App\Module\Project\Command\ListProjectsCommand;
use App\Module\Project\Command\ListProjectsHandler;
use App\Module\Project\View\ProjectListItem;
use App\Session\ReadOnlyAwareSessionHandler;
use App\Utils\RowChanges;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/** The rows of a page of the projects list, as streams that keep each row in place. Page one reads its page again and inserts the rows it gained. */
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
        private readonly ListProjectsHandler $listProjects,
    ) {
    }

    public function __invoke(
        #[MapQueryParameter] string $ids = '',
        #[MapQueryParameter] string $since = '',
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if (1 === $page) {
            $first = ($this->listProjects)(new ListProjectsCommand($user, 1));
            $shown = array_filter(
                \array_slice('' === $ids ? [] : explode(',', $ids), 0, ListProjectRowsHandler::MAX_IDS),
                static fn (string $id): bool => Uuid::isValid($id),
            );
            $changes = RowChanges::between(
                $first->items,
                static fn (ProjectListItem $item): string => (string) $item->project->id,
                array_values(array_unique($shown)),
            );
            $html = $this->renderView('@Project/_project_first_page_rows.stream.html.twig', [
                'items' => $first->items,
                'changes' => $changes,
                'totalPages' => $first->totalPages,
                'pageList' => $first->pageList,
            ]);

            return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
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
