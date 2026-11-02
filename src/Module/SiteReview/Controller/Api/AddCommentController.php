<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A widget saves a note through `POST /api/board/feedback` now, which gives
 * every note a card. A browser can hold a copy of the script from before, so
 * this path stays and tells that copy to reload rather than answering 404.
 */
#[Route(
    '/api/live-review/comments',
    name: 'api_site_review_comment_add',
    methods: ['POST'],
)]
final class AddCommentController extends AppController
{
    public function __invoke(): JsonResponse
    {
        throw ApiProblemException::refused(JsonResponse::HTTP_GONE, 'widget_outdated', 'This widget version is no longer served.');
    }
}
