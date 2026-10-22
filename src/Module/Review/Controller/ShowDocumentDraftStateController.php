<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ShowDraftStateCommand;
use App\Module\Review\Command\ShowDraftStateHandler;
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
 * The band and the Finish review warning of a draft, as the open page fetches
 * them after a signal. A document that is no longer a draft answers no content,
 * and the page loads again. A page that offers no publish asks with publish=0.
 */
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/draft-state',
    name: 'app_document_draft_state',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowDocumentDraftStateController extends AppController
{
    public function __construct(
        private readonly ShowDraftStateHandler $showDraftState,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        Request $request,
    ): Response {
        $state = ($this->showDraftState)(new ShowDraftStateCommand($document));
        if (null === $state) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $html = $this->renderView('@Review/_draft_state.stream.html.twig', [
            'document' => $document,
            'draftReviewLoop' => $state->loop,
            'showPublish' => $request->query->getBoolean('publish', true),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
