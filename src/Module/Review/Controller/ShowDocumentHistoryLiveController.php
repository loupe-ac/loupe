<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\ShowDocumentHistoryCommand;
use App\Module\Review\Command\ShowDocumentHistoryHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The history page as a Turbo Stream: the version count and the Finish review
 * button of the top bar, the compare picker, and the table with the verdict log.
 */
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/history/live',
    name: 'app_document_history_live',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowDocumentHistoryLiveController extends AppController
{
    public function __construct(
        private readonly ShowDocumentHistoryHandler $showDocumentHistory,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $view = ($this->showDocumentHistory)(new ShowDocumentHistoryCommand($document));
        $html = $this->renderView('@Review/_history.stream.html.twig', [
            'document' => $view->document,
            'versions' => $view->versions,
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
