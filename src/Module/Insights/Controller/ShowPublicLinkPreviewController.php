<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowPublicLinkPreviewCommand;
use App\Module\Insights\Command\ShowPublicLinkPreviewHandler;
use App\Module\Insights\Service\PublicLinkCode;
use App\Module\Landing\Service\PublicPages;
use App\Module\Landing\Twig\LandingExtension;
use Sentry\Unit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The preview image of a shared item. It ignores the `v` value, reads no session and sets no cookie. When no confirmed
 * image exists, it sends the generic Loupe image, which shows no numbers, and tells caches to keep nothing.
 */
#[Route(
    '/s/{code}/preview.png',
    name: 'app_public_link_preview',
    requirements: ['code' => PublicLinkCode::PATTERN],
    methods: ['GET'],
)]
final class ShowPublicLinkPreviewController extends AppController
{
    public function __construct(
        private readonly ShowPublicLinkPreviewHandler $showPublicLinkPreview,
        private readonly LandingExtension $landing,
        private readonly PublicPages $publicPages,

        #[Autowire(service: 'limiter.public_link_image')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(Request $request, string $code): Response
    {
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many views.');
        }

        $startedAt = hrtime(true);
        $view = ($this->showPublicLinkPreview)(new ShowPublicLinkPreviewCommand($code));

        if (null === $view->png) {
            $response = new RedirectResponse($this->publicPages->absoluteUrl($this->landing->openGraphImagePath()));
            $response->headers->set('Cache-Control', 'no-store');
        } else {
            $response = new Response($view->png, Response::HTTP_OK, ['Content-Type' => 'image/png']);
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        }
        \Sentry\traceMetrics()->distribution('public_preview.serve_ms', (hrtime(true) - $startedAt) / 1_000_000, ['source' => $view->source], Unit::millisecond());

        return $response;
    }
}
