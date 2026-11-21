<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Inbox\Command\ShowInboxCommand;
use App\Module\Inbox\Command\ShowInboxHandler;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\View\InboxRow;
use App\Module\Inbox\View\InboxRowInsert;
use App\Module\Inbox\View\InboxSide;
use App\Module\Inbox\View\InboxView;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The rows of the asks that a live signal named, as a stream for the open
 * side of the list. Each changed row leaves the page and comes back before the
 * next row that did not change, so the order of the handler holds. The header
 * lists every row key of the view, so the page can check that its unchanged
 * rows are the same ones, in the same order, before it plays the stream.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/inbox/rows',
    name: 'app_project_inbox_rows',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowInboxRowsController extends AppController
{
    public const string KEYS_HEADER = 'X-Loupe-Inbox-Row-Keys';

    public const int MAX_CHANGED = 50;

    public function __construct(
        private readonly ShowInboxHandler $showInbox,
        private readonly InboxAvailability $inbox,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->inbox->requireEnabled();
        $view = InboxView::fromQuery($request->query);
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_project_inbox', ['id' => (string) $project->id, ...$view->toQuery()]);
        }
        if ($view->isSearch() || InboxSide::Open !== $view->side) {
            throw new BadRequestHttpException('Only the open side of the list reads by row.');
        }

        $named = $request->query->all('changed');
        if (\count($named) > self::MAX_CHANGED) {
            throw new BadRequestHttpException('Too many changed asks to read by row.');
        }

        $changed = [];
        foreach ($named as $id) {
            if (\is_string($id) && Uuid::isValid($id)) {
                $changed[] = Uuid::fromString($id)->toRfc4122();
            }
        }

        $page = ($this->showInbox)(new ShowInboxCommand($project, $view));
        $keys = array_map(static fn (InboxRow $row): string => $row->key(), $page->rows);

        $inserts = [];
        foreach ($page->rows as $index => $row) {
            if (!\in_array($row->key(), $changed, true)) {
                continue;
            }
            $before = array_find(\array_slice($keys, $index + 1), fn ($next) => !\in_array($next, $changed, true));
            $inserts[] = new InboxRowInsert($row, $before);
        }

        return new Response(
            $this->renderView('@Inbox/_inbox_rows.stream.html.twig', [
                'inbox' => $page,
                'changed' => $changed,
                'inserts' => $inserts,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => TurboBundle::STREAM_MEDIA_TYPE,
                self::KEYS_HEADER => implode(',', $keys),
            ],
        );
    }
}
