<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ListLatestCommentsCommand;
use App\Module\Review\Command\ListLatestCommentsHandler;
use App\Module\Review\Command\ShowLiveThreadCommand;
use App\Module\Review\Command\ShowLiveThreadHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * One thread, as the open review page reads it after another actor replied.
 * A thread that is gone, deleted or on an older version answers every thread
 * instead, because a page cannot tell it from a new one.
 */
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/threads/{commentId}',
    name: 'app_document_thread',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowDocumentThreadController extends AppController
{
    public function __construct(
        private readonly ShowLiveThreadHandler $showLiveThread,
        private readonly ListLatestCommentsHandler $listLatestComments,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        string $commentId,
    ): Response {
        $thread = ($this->showLiveThread)(new ShowLiveThreadCommand($document, $commentId));

        $html = null === $thread
            ? $this->renderView('@Review/_comment_added.stream.html.twig', [
                'comments' => ($this->listLatestComments)(new ListLatestCommentsCommand($document))->comments,
                'morph' => true,
            ])
            : $this->renderView('@Review/_comment_thread.stream.html.twig', [
                'comment' => $thread->root,
                'replies' => $thread->replies,
                'replyForm' => null,
                'morph' => true,
            ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
