<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ShowLiveBandCommand;
use App\Module\Review\Command\ShowLiveBandHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The bands that an open review page shows after another actor's change: a
 * newer version than the page shows, and the archive state of the document.
 */
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/live-band',
    name: 'app_document_live_band',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowDocumentLiveBandController extends AppController
{
    public function __construct(
        private readonly ShowLiveBandHandler $showLiveBand,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        Request $request,
    ): Response {
        $view = ($this->showLiveBand)(new ShowLiveBandCommand($document, max(0, $request->query->getInt('shown'))));

        $html = $this->renderView('@Review/_live_band.stream.html.twig', [
            'document' => $document,
            'archived' => $view->archived,
            'newerVersion' => $view->newerVersionNumber,
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
