<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Review\Command\ShowDocumentCommand;
use App\Module\Review\Command\ShowDocumentHandler;
use App\Module\Review\Command\ShowDraftStateCommand;
use App\Module\Review\Command\ShowDraftStateHandler;
use App\Module\Review\Command\ShowStandingVerdictCommand;
use App\Module\Review\Command\ShowStandingVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\SubmitReviewFormType;
use App\Module\Review\Form\SubmitReviewRequest;
use App\Module\Review\Form\UndoVerdictFormType;
use App\Module\Review\Form\UndoVerdictRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/**
 * The answer of a verdict, an undo or a publish: every part of the review page
 * that the document status drives, plus one flash.
 */
final readonly class DocumentStateStreams
{
    public function __construct(
        private ShowDraftStateHandler $showDraftState,
        private ShowStandingVerdictHandler $showStandingVerdict,
        private ShowDocumentHandler $showDocument,
        private DraftReviewLoops $draftReviewLoops,
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function answer(Document $document, User $reader, string $severity, ?string $message): Response
    {
        $routeParameters = [
            'projectId' => (string) $document->project->id,
            'documentId' => (string) $document->id,
        ];
        $view = ($this->showDocument)(new ShowDocumentCommand($document, null, $reader));
        $review = ($this->showStandingVerdict)(new ShowStandingVerdictCommand($document));
        $state = ($this->showDraftState)(new ShowDraftStateCommand($document));

        $html = null === $state
            ? $this->twig->render('@Review/_draft_cleared.stream.html.twig')
            : $this->twig->render('@Review/_draft_state.stream.html.twig', [
                'document' => $document,
                'draftReviewLoop' => $state->loop,
                'showPublish' => true,
            ]);
        $html .= $this->twig->render('@Review/_topbar_actions.stream.html.twig', [
            'document' => $document,
            'review' => $review,
            'undoVerdictForm' => $this->formFactory->create(
                UndoVerdictFormType::class,
                new UndoVerdictRequest(reviewId: $review?->id?->toRfc4122()),
                ['action' => $this->urlGenerator->generate('app_document_review_undo', $routeParameters)],
            )->createView(),
        ]);
        $html .= $this->twig->render('@Review/_history_topbar_actions.stream.html.twig', ['document' => $document]);
        $html .= $this->twig->render('@Review/_finish_review_form.stream.html.twig', [
            'document' => $document,
            'submitReviewForm' => $this->formFactory->create(
                SubmitReviewFormType::class,
                new SubmitReviewRequest(versionNumber: $view->version->versionNumber, expectedReviewId: $view->latestReviewId),
                ['action' => $this->urlGenerator->generate('app_document_review_submit', $routeParameters)],
            )->createView(),
            'signals' => $view->signals,
            'decisions' => $view->decisions,
            'draftReviewLoop' => $this->draftReviewLoops->of($document),
        ]);
        if (null !== $message) {
            $html .= $this->twig->render('_flash.stream.html.twig', ['severity' => $severity, 'message' => $message]);
        }

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
