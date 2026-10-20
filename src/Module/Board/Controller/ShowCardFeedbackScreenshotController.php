<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowCardFeedbackScreenshotCommand;
use App\Module\Board\Command\ShowCardFeedbackScreenshotHandler;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Security\CardFeedbackVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves the screenshot a reviewer saved with a note. The stored type is one
 * the save accepted, and nosniff holds the browser to it.
 */
#[IsGranted(CardFeedbackVoter::VIEW, subject: 'link')]
#[Route(
    '/board/feedback/{id:link}/screenshot',
    name: 'app_card_feedback_screenshot',
    methods: ['GET'],
)]
final class ShowCardFeedbackScreenshotController extends AppController
{
    public function __construct(
        private readonly ShowCardFeedbackScreenshotHandler $handler,
    ) {
    }

    public function __invoke(CardSiteReviewComment $link): Response
    {
        $image = ($this->handler)(new ShowCardFeedbackScreenshotCommand($link->comment))
            ?? throw new NotFoundHttpException('This note has no screenshot.');

        return new Response($image->bytes, Response::HTTP_OK, [
            'Content-Type' => $image->contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
