<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Command\ListDocumentRowsCommand;
use App\Module\Review\Command\ListDocumentRowsHandler;
use App\Module\Review\View\DocumentListQuery;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The rows that a later page of the documents list shows, as the open page
 * reads them after a signal. The list state travels in the query, like the list.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/documents/live-rows',
    name: 'app_project_documents_live_rows',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET', 'POST'],
)]
final class ListDocumentRowsController extends AppController
{
    public function __construct(
        private readonly ListDocumentRowsHandler $listDocumentRows,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $listQuery = DocumentListQuery::fromQuery($request->query);
        $ids = array_values(array_filter(explode(',', $request->query->getString('ids')), static fn (string $id): bool => '' !== $id));
        $view = ($this->listDocumentRows)(new ListDocumentRowsCommand(
            $project,
            $listQuery,
            $ids,
            new \DateTimeImmutable()->setTimestamp(max(0, $request->query->getInt('since'))),
            Uuid::isValid($request->query->getString('sinceId')) ? Uuid::fromString($request->query->getString('sinceId')) : null,
            $request->query->has('total') ? max(0, $request->query->getInt('total')) : null,
            $request->request->has('known') ? array_values(array_filter(explode(',', $request->request->getString('known')), static fn (string $id): bool => '' !== $id)) : null,
        ));

        $html = $this->renderView('@Review/_document_rows.stream.html.twig', [
            'project' => $project,
            'listQuery' => $listQuery,
            'items' => $view->items,
            'removedIds' => $view->removedIds,
            'newerCount' => $view->newerCount,
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
