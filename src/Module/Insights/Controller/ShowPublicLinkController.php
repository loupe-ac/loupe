<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ShowPublicLinkCommand;
use App\Module\Insights\Command\ShowPublicLinkHandler;
use App\Module\Insights\Service\PublicLinkCode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/** The public page of a shared item. It reads no session and sets no cookie. */
#[Route(
    '/s/{code}',
    name: 'app_public_link',
    requirements: ['code' => PublicLinkCode::PATTERN],
    methods: ['GET'],
)]
final class ShowPublicLinkController extends AppController
{
    public function __construct(
        private readonly ShowPublicLinkHandler $showPublicLink,

        #[Autowire(service: 'limiter.public_link')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(Request $request, string $code): Response
    {
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many views.');
        }

        $view = ($this->showPublicLink)(new ShowPublicLinkCommand($code));

        return $this->render('@Insights/show_public_link.html.twig', ['view' => $view]);
    }
}
