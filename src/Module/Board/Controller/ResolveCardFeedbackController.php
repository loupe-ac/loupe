<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowCardFeedbackSizesHandler;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Security\CardFeedbackVoter;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('site-review-comment-action')]
#[IsGranted(CardFeedbackVoter::RESOLVE, subject: 'link')]
#[Route(
    '/board/feedback/{id:link}/{surface}/resolve',
    name: 'app_card_feedback_resolve',
    requirements: ['surface' => 'conversation|feedback'],
    methods: ['POST'],
)]
final class ResolveCardFeedbackController extends AppController
{
    public function __construct(
        private readonly ResolveSiteReviewCommentHandler $handler,
        private readonly ShowCardFeedbackSizesHandler $sizes,
    ) {
    }

    public function __invoke(Request $request, CardSiteReviewComment $link, string $surface): Response
    {
        ($this->handler)(new ResolveSiteReviewCommentCommand($link->comment));

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return new Response(
                $this->renderView('@Board/_feedback_entry.stream.html.twig', ['link' => $link, 'surface' => $surface, 'screenshotSizes' => ($this->sizes)($link)]),
                Response::HTTP_OK,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        return $this->redirectToRoute('app_board_card', [
            'projectId' => (string) $link->card->project->id,
            'cardId' => (string) $link->card->id,
            'tab' => $surface,
        ]);
    }
}
